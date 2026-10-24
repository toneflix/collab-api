<?php

namespace App\Services\Studio;

use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\ProjectTrackVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The version history of a project track: records each change to its
 * arrangement with who made it, and restores earlier versions.
 */
class TrackHistory
{
    /** Back-to-back edits of the same kind by one person within this many seconds share a version */
    public const MERGE_WINDOW = 120;

    /** Edits that merge into the version before them */
    protected const MERGEABLE = [ProjectTrackVersion::ADDED, ProjectTrackVersion::EDITED];

    /** Meta that belongs to a stem's audio rather than to where it sits */
    protected const AUDIO_META = ['instrument', 'duration', 'cuein', 'cueout', 'clips'];

    public function __construct(protected ProjectTrack $track) {}

    public static function for(ProjectTrack $track): self
    {
        return new self($track);
    }

    /**
     * The track's arrangement as it is now.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $this->track->refresh();
        $meta = $this->track->meta ?? collect();

        return [
            'title' => $this->track->title,
            'bpm' => isset($meta['bpm']) ? (float) $meta['bpm'] : null,
            'timeSignature' => $meta['timeSignature'] ?? null,
            'stems' => $this->track->stems()->orderBy('id')->get()->map(fn (ProjectTrackStem $stem) => [
                'id' => $stem->id,
                'title' => $stem->title,
                'src' => $stem->getRawOriginal('src'),
                'user_id' => $stem->user_id,
                'stem_id' => $stem->stem_id,
                'meta' => collect(json_decode((string) $stem->getRawOriginal('meta'), true) ?: [])->except('src')->all(),
            ])->all(),
        ];
    }

    public function latest(): ?ProjectTrackVersion
    {
        return $this->track->versions()->latest('id')->first();
    }

    /**
     * Keep the track as it was before its first recorded change, so the
     * first change can be undone too. Call it before changing the track.
     */
    public function baseline(): void
    {
        if ($this->track->versions()->exists()) {
            return;
        }

        $snapshot = $this->snapshot();

        $this->track->versions()->create([
            'action' => ProjectTrackVersion::ORIGINAL,
            'summary' => 'the track before version history',
            'changes' => [],
            'snapshot' => $snapshot,
            'stem_ids' => $this->stemIds($snapshot),
        ]);
    }

    /**
     * Record the track as it is now. Returns null when nothing changed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(?User $user, string $action, array $attributes = []): ?ProjectTrackVersion
    {
        $snapshot = $this->snapshot();
        $latest = $this->latest();

        if ($latest && $this->merges($latest, $user, $action)) {
            $before = $this->track->versions()->where('id', '<', $latest->id)->latest('id')->first();
            $diff = ArrangementDiff::between($before?->snapshot, $snapshot);

            if ($diff->isEmpty()) {
                $latest->delete();

                return null;
            }

            $latest->update([
                'summary' => $diff->summary(),
                'changes' => $diff->changes(),
                'snapshot' => $snapshot,
                'stem_ids' => $this->stemIds($snapshot),
            ]);

            return $latest;
        }

        $diff = ArrangementDiff::between($latest?->snapshot, $snapshot);

        if ($diff->isEmpty()) {
            return null;
        }

        return $this->track->versions()->create([
            ...$attributes,
            'user_id' => $user?->id,
            'action' => $action,
            'summary' => $diff->summary(),
            'changes' => $diff->changes(),
            'snapshot' => $snapshot,
            'stem_ids' => $this->stemIds($snapshot),
        ]);
    }

    /**
     * Put the track back the way it was in a version. Stems added since are
     * removed (and can be brought back by a later version), removed ones come
     * back. A stem whose audio was replaced since keeps its new audio.
     *
     * @return array{version: ?ProjectTrackVersion, missing: array<int, string>, kept_audio: array<int, string>}
     */
    public function restore(ProjectTrackVersion $version, User $user): array
    {
        return DB::transaction(function () use ($version, $user) {
            $target = collect($version->snapshot['stems'] ?? [])->keyBy('id');
            $missing = [];
            $keptAudio = [];

            $this->track->stems()->whereNotIn('id', $target->keys())->get()->each->delete();

            $stems = $this->track->stems()->withTrashed()->whereIn('id', $target->keys())->get()->keyBy('id');

            foreach ($target as $id => $data) {
                /** @var ProjectTrackStem|null $stem */
                $stem = $stems->get($id);
                $title = (string) (($data['title'] ?? '') ?: 'Stem '.$id);

                if (! $stem) {
                    $missing[] = $title;

                    continue;
                }

                $meta = (array) ($data['meta'] ?? []);

                if ($stem->getRawOriginal('src') !== ($data['src'] ?? null)) {
                    $current = json_decode((string) $stem->getRawOriginal('meta'), true) ?: [];
                    $meta = [...collect($meta)->except(self::AUDIO_META)->all(), ...collect($current)->only(self::AUDIO_META)->all()];
                    $keptAudio[] = $title;
                }

                if ($stem->trashed()) {
                    $stem->deleted_at = null;
                }

                $stem->title = $data['title'] ?? $stem->title;
                $stem->meta = $meta;
                $stem->saveQuietly();
            }

            $trackMeta = collect($this->track->meta ?? []);
            foreach (['bpm', 'timeSignature'] as $key) {
                $value = $version->snapshot[$key] ?? null;
                $value === null ? $trackMeta->forget($key) : $trackMeta->put($key, $value);
            }

            $this->track->title = $version->snapshot['title'] ?? $this->track->title;
            $this->track->meta = $trackMeta;
            $this->track->save();

            return [
                'version' => $this->record($user, ProjectTrackVersion::RESTORED, ['restored_from_id' => $version->id]),
                'missing' => $missing,
                'kept_audio' => $keptAudio,
            ];
        });
    }

    /**
     * Drop versions older than the cutoff, keeping the newest, then delete
     * for good the removed stems no remaining version can bring back.
     */
    public function prune(Carbon $cutoff): int
    {
        $latestId = $this->track->versions()->max('id');

        $pruned = $this->track->versions()
            ->where('created_at', '<', $cutoff)
            ->where('id', '<', $latestId)
            ->delete();

        $this->track->stems()->onlyTrashed()->chunkById(100, function ($stems) {
            $stems->each(function (ProjectTrackStem $stem) {
                $needed = $this->track->versions()->whereJsonContains('stem_ids', $stem->id)->exists();

                if (! $needed) {
                    $stem->forceDelete();
                }
            });
        });

        return $pruned;
    }

    protected function merges(ProjectTrackVersion $latest, ?User $user, string $action): bool
    {
        return in_array($action, self::MERGEABLE, true)
            && $latest->action === $action
            && $user
            && (int) $latest->user_id === (int) $user->id
            && $latest->updated_at?->gt(now()->subSeconds(self::MERGE_WINDOW));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, int>
     */
    protected function stemIds(array $snapshot): array
    {
        return array_map(fn ($stem) => (int) $stem['id'], $snapshot['stems'] ?? []);
    }
}
