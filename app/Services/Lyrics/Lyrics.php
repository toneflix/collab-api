<?php

namespace App\Services\Lyrics;

use App\Broadcasting\Channels;
use App\Http\Resources\LyricResource;
use App\Models\Lyric;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Models\User;
use App\Services\Moderation\Moderation;
use App\Services\Realtime\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Saves the lyrics of released tracks and project tracks. Saving checks the
 * words, marks released tracks explicit, sends words to review to the
 * moderation queue, and passes a project track's lyrics on to the tracks
 * released from it.
 */
class Lyrics
{
    public const MAX_LINES = 1000;

    public const MAX_LINE_LENGTH = 500;

    public function __construct(
        protected LyricsCheck $check,
        protected Moderation $moderation,
    ) {}

    public function for(Track|ProjectTrack $owner): ?Lyric
    {
        return Lyric::query()->whereMorphedTo('lyricable', $owner)->first();
    }

    /**
     * Save lyrics, or remove them when there are no lines.
     *
     * @param  array<int, mixed>  $lines
     * @param  array{language?: ?string, ai_job_id?: ?int}  $extra
     */
    public function save(Track|ProjectTrack $owner, array $lines, string $source, ?User $by = null, array $extra = []): ?Lyric
    {
        $lines = $this->normalize($lines);
        $text = implode("\n", array_column($lines, 'text'));

        $lyric = DB::transaction(function () use ($owner, $lines, $text, $source, $by, $extra) {
            if (! $lines) {
                Lyric::query()->whereMorphedTo('lyricable', $owner)->delete();

                return null;
            }

            $flagged = $this->check->scan($text);

            $lyric = Lyric::query()->whereMorphedTo('lyricable', $owner)->first() ?? new Lyric;
            $lyric->lyricable()->associate($owner);
            $lyric->fill([
                'lines' => $lines,
                'text' => $text,
                'language' => $extra['language'] ?? ($source === Lyric::WRITTEN ? $lyric->language : null),
                'source' => $source,
                'explicit' => (bool) $flagged['explicit'],
                'flagged' => $flagged,
                'ai_job_id' => $extra['ai_job_id'] ?? null,
                'updated_by' => $by?->id,
            ])->save();

            return $lyric;
        });

        if ($owner instanceof Track) {
            $this->applyToTrack($owner, $lyric);
        } else {
            $this->passOn($owner, $lines, $source, $by, $extra);

            Realtime::send(Channels::projectTrack($owner), 'lyrics.updated', [
                'track_id' => $owner->id,
                'lyrics' => $lyric ? (new LyricResource($lyric))->resolve() : null,
            ], $by);
        }

        return $lyric;
    }

    /**
     * Give a released track the lyrics of the project track it came from.
     */
    public function inherit(Track $track): ?Lyric
    {
        $source = $track->project_track_id
            ? Lyric::query()->where('lyricable_type', (new ProjectTrack)->getMorphClass())->where('lyricable_id', $track->project_track_id)->first()
            : null;

        if (! $source) {
            return null;
        }

        return $this->save($track, $source->lines, $source->source, $source->editor, [
            'language' => $source->language,
            'ai_job_id' => $source->ai_job_id,
        ]);
    }

    /**
     * Timed lines with tidy text, in the order given. A line without a time
     * keeps null, so lyrics can be written before they're synced.
     *
     * @param  array<int, mixed>  $lines
     * @return list<array{start: ?float, end: ?float, text: string}>
     */
    public function normalize(array $lines): array
    {
        return collect($lines)
            ->map(fn($line) => is_string($line) ? ['text' => $line] : (array) $line)
            ->map(fn(array $line) => [
                'start' => $this->time($line['start'] ?? null),
                'end' => $this->time($line['end'] ?? null),
                'text' => str(preg_replace('/\s+/u', ' ', (string) ($line['text'] ?? '')))->trim()->limit(self::MAX_LINE_LENGTH, '')->toString(),
            ])
            ->filter(fn(array $line) => $line['text'] !== '')
            ->take(self::MAX_LINES)
            ->values()
            ->all();
    }

    protected function time(mixed $value): ?float
    {
        return is_numeric($value) && $value >= 0 ? round((float) $value, 2) : null;
    }

    protected function applyToTrack(Track $track, ?Lyric $lyric): void
    {
        $explicit = (bool) $lyric?->explicit;

        if ((bool) $track->explicit !== $explicit) {
            $track->forceFill(['explicit' => $explicit])->saveQuietly();
        }

        if ($review = $lyric?->flagged['review'] ?? []) {
            $this->moderation->flagLyrics($track, $review);
        }
    }

    /**
     * Copy a project track's lyrics to the tracks released from it.
     *
     * @param  list<array{start: ?float, end: ?float, text: string}>  $lines
     * @param  array{language?: ?string, ai_job_id?: ?int}  $extra
     */
    protected function passOn(ProjectTrack $owner, array $lines, string $source, ?User $by, array $extra): void
    {
        Track::query()
            ->withoutGlobalScopes()
            ->where('project_track_id', $owner->id)
            ->chunkById(100, function ($tracks) use ($lines, $source, $by, $extra) {
                foreach ($tracks as $track) {
                    $this->save($track, $lines, $source, $by, $extra);
                }
            });
    }
}
