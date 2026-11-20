<?php

namespace App\Services\Ai\Video;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Audio\TrackAudio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Toneflix\LaravelFileable\Media;

/**
 * Makes music videos for released tracks: starts the storyboard, lets the
 * owner change shots before and after rendering, then renders and stitches,
 * paid from the plan's video time when it has enough, else from AI credits.
 */
class MusicVideos
{
    /** Statuses in which the shots can be changed and the video saved */
    public const EDITABLE = [MusicVideo::STORYBOARD, MusicVideo::READY];

    public function __construct(
        protected AiStudio $studio,
        protected TrackAudio $audio,
        protected VideoStorage $storage,
        protected ShotTakes $takes,
    ) {}

    /**
     * Start a video for the track and draw its storyboard. A photo is only
     * taken from plans that allow the owner's own photos.
     *
     * Character sheets the creator gives are used as they are and none are
     * drawn for the video.
     *
     * @param  array{ratio?: string, brief?: ?string}  $options
     * @param  list<array{name: string, description?: ?string, singer?: bool, sheet: UploadedFile}>  $characters
     */
    public function create(User $user, Track $track, array $options, ?UploadedFile $photo = null, array $characters = []): MusicVideo
    {
        if (($photo || $characters) && ! $user->feature(Feature::VIDEO_OWN_PHOTOS)) {
            throw ValidationException::withMessages([
                $photo ? 'photo' : 'characters' => 'Your plan doesn\'t include music videos from your own images.',
            ]);
        }

        if ($characters && count(array_filter($characters, fn(array $character) => ! empty($character['singer']))) !== 1) {
            throw ValidationException::withMessages(['characters' => 'Mark which character sheet is the singer.']);
        }

        $duration = (float) ($track->duration ?: $this->audio->measure($track));

        if ($duration <= 0) {
            throw ValidationException::withMessages(['track' => 'This track has no audio to make a video for.']);
        }

        if ($duration > AiSettings::videoMaxSeconds()) {
            throw ValidationException::withMessages(['track' => 'This track is too long for a music video.']);
        }

        $video = MusicVideo::query()
            ->create([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'ratio' => $options['ratio'] ?? '16:9',
                'brief' => $options['brief'] ?? null,
                'duration' => round($duration, 2),
            ]);

        try {
            if ($characters) {
                $this->keepCharacters($video, $characters);
            } else {
                $this->keepPortrait($video, $user, $track, $photo);
            }

            $this->studio->start($user, null, 'video_storyboard', [
                'music_video_id' => $video->id,
                'title' => $track->title,
                'minutes' => $video->startedMinutes(),
            ]);
        } catch (Throwable $e) {
            $this->storage->forget($video);
            $video->delete();

            throw $e;
        }

        return $video;
    }

    /**
     * Store the creator's own character sheets, the singer keyed as such.
     *
     * @param  list<array{name: string, description?: ?string, singer?: bool, sheet: UploadedFile}>  $characters
     */
    protected function keepCharacters(MusicVideo $video, array $characters): void
    {
        $keys = [];

        foreach ($characters as $character) {
            $singer = ! empty($character['singer']);
            $base = $singer
                ? MusicVideoCharacter::SINGER
                : (Str::slug($character['name'], '_') ?: 'character');
            $base = Str::limit($base, 36, '');
            $key = $base;

            for ($i = 2; in_array($key, $keys, true) || (! $singer && $key === MusicVideoCharacter::SINGER); $i++) {
                $key = "{$base}_{$i}";
            }

            $keys[] = $key;
            $sheet = $character['sheet'];
            $file = $this->storage->put($video, (string) $sheet->getRealPath(), "character-{$key}", $sheet->extension() ?: 'jpg');

            $video
                ->characters()
                ->create([
                    'key' => $key,
                    'name' => $character['name'],
                    'description' => $character['description'] ?? '',
                    'singer' => $singer,
                    'sheet' => $file,
                    'provided' => true,
                ]);
        }
    }

    /**
     * Store the photo the singing shots are drawn from: the uploaded one, else
     * the picture of the track's main artist when the user manages that artist.
     */
    protected function keepPortrait(MusicVideo $video, User $user, Track $track, ?UploadedFile $photo): void
    {
        if ($photo) {
            $file = $this->storage->put($video, (string) $photo->getRealPath(), 'portrait', $photo->extension() ?: 'jpg');

            $video
                ->forceFill(['portrait' => $file])
                ->save();

            return;
        }

        $artist = $track
            ->artist()
            ->first();

        if (! $artist instanceof Artist || ! $artist->isManagedBy($user) || ! $artist->image) {
            return;
        }

        $media = new Media;
        $path = $media->mediaInfo('avatar', $artist->image)['path'] ?? null;

        if (! $path || ! $media->disk->exists($path)) {
            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'portrait-');
        $content = $media->disk->get($path);

        file_put_contents($local, $content);

        try {
            $file = $this->storage->put($video, $local, 'portrait', pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');

            $video
                ->forceFill(['portrait' => $file])
                ->save();
        } finally {
            @unlink($local);
        }
    }

    /**
     * Change what a shot shows. The take it played is kept with its earlier
     * ones, so the next save renders a new clip from the shot's still.
     */
    public function updateShot(MusicVideo $video, MusicVideoShot $shot, string $prompt): MusicVideoShot
    {
        $this->ensureStatus($video, self::EDITABLE);

        DB::transaction(function () use ($video, $shot, $prompt) {
            $shot
                ->forceFill(['prompt' => $prompt, 'take_id' => null, 'offset' => 0, 'motion' => null])
                ->save();
            $video
                ->forceFill(['edited_at' => now()])
                ->save();
        });

        $this->takes->prune($video);

        return $shot;
    }

    /**
     * Save the whole strip of shots as the editor has it: their order, where
     * each starts and ends, the take each plays from where, or the motion
     * over its still. Shots left out are removed, and a shot copied from
     * another (a split) starts as that shot. The shots must cover the song
     * from start to end with no gaps.
     *
     * @param  list<array{id?: ?int, copy_of?: ?int, start: float|int|string, end: float|int|string, take_id?: ?int, offset?: float|int|string|null, motion?: ?string}>  $items
     */
    public function saveShots(MusicVideo $video, array $items): MusicVideo
    {
        $this->ensureStatus($video, self::EDITABLE);

        $shots = $video
            ->shots()
            ->with('take')
            ->get()
            ->keyBy('id');
        $takes = MusicVideoTake::query()
            ->where('music_video_id', $video->id)
            ->take(1000)
            ->get()
            ->keyBy('id');
        $rows = $this->checkStrip($video, $items, $shots, $takes);
        $removed = $shots->except(array_filter(array_column($rows, 'id')));

        DB::transaction(function () use ($video, $rows, $shots, $removed) {
            // Positions are unique per video, so move them all out of the way first.
            $video
                ->shots()
                ->increment('position', 1000);

            MusicVideoShot::query()
                ->whereKey($removed->keys())
                ->delete();

            foreach ($rows as $i => $row) {
                $values = [
                    'position' => $i + 1,
                    'start' => $row['start'],
                    'end' => $row['end'],
                    'take_id' => $row['take_id'],
                    'offset' => $row['offset'],
                    'motion' => $row['motion'],
                ];

                if ($row['id']) {
                    MusicVideoShot::query()
                        ->whereKey($row['id'])
                        ->update($values);

                    continue;
                }

                $source = $shots[$row['copy_of']];

                MusicVideoShot::query()
                    ->create([
                        ...$values,
                        'music_video_id' => $video->id,
                        'kind' => $source->kind,
                        'prompt' => $source->prompt,
                        'keyframe' => $source->keyframe,
                        'characters' => $source->characters,
                    ]);
            }

            $video
                ->forceFill(['edited_at' => now()])
                ->save();
        });

        $this->takes->prune($video);
        $this->takes->dropKeyframes($video, $removed->pluck('keyframe')->all());

        return $video;
    }

    /**
     * Put the shots back as the finished video was cut from them.
     */
    public function discardEdits(MusicVideo $video): MusicVideo
    {
        $this->ensureStatus($video, [MusicVideo::READY]);

        if (! $video->cut) {
            throw ValidationException::withMessages(['video' => 'This video has no saved cut to go back to.']);
        }

        $this->ensureNotDrawing($video);

        $kept = array_column($video->cut, 'shot');
        $removed = $video
            ->shots()
            ->pluck('keyframe')
            ->all();
        $takes = MusicVideoTake::query()
            ->where('music_video_id', $video->id)
            ->whereKey(array_filter(array_column($video->cut, 'take')))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($video, $kept, $takes) {
            $video
                ->shots()
                ->whereKeyNot($kept)
                ->delete();
            $video
                ->shots()
                ->increment('position', 1000);

            foreach ($video->cut as $i => $entry) {
                $take = in_array((int) $entry['take'], $takes, true) ? (int) $entry['take'] : null;

                $shot = MusicVideoShot::query()
                    ->findOrNew($entry['shot']);

                $shot
                    ->forceFill([
                        'id' => $entry['shot'],
                        'music_video_id' => $video->id,
                        'position' => $i + 1,
                        'start' => $entry['start'],
                        'end' => $entry['end'],
                        'kind' => $entry['kind'],
                        'prompt' => $entry['prompt'],
                        'keyframe' => $entry['keyframe'],
                        'characters' => $entry['characters'],
                        'take_id' => $take,
                        'offset' => $entry['offset'],
                        'motion' => $entry['motion'],
                    ])
                    ->save();
            }

            $video
                ->forceFill(['edited_at' => null])
                ->save();
        });

        $this->takes->prune($video);
        $this->takes->dropKeyframes($video, $removed);

        return $video;
    }

    /**
     * Check a strip sent by the editor and turn it into rows to save.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, MusicVideoShot>  $shots
     * @param  Collection<int, MusicVideoTake>  $takes
     * @return list<array{id: ?int, copy_of: ?int, start: float, end: float, take_id: ?int, offset: float, motion: ?string}>
     */
    protected function checkStrip(MusicVideo $video, array $items, Collection $shots, Collection $takes): array
    {
        $rows = [];
        $seen = [];
        $at = 0.0;

        foreach (array_values($items) as $i => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            $copyOf = isset($item['copy_of']) ? (int) $item['copy_of'] : null;
            $start = round((float) $item['start'], 2);
            $end = round((float) $item['end'], 2);
            $shot = $shots[$id ?? $copyOf] ?? null;
            $takeId = array_key_exists('take_id', $item) ? ($item['take_id'] === null ? null : (int) $item['take_id']) : $shot?->take_id;
            $offset = round((float) ($item['offset'] ?? 0), 2);
            $motion = $item['motion'] ?? null;
            $error = match (true) {
                ! $shot => 'A shot isn\'t part of this video.',
                $id !== null && in_array($id, $seen, true) => 'Send each shot once.',
                abs($start - $at) > 0.05 => 'Shots must follow each other with no gaps.',
                $end - $start < MusicVideoShot::MIN_SECONDS - 0.01 => 'A shot must be at least ' . MusicVideoShot::MIN_SECONDS . ' second long.',
                $takeId !== null && ! $takes->has($takeId) => 'A take isn\'t part of this video.',
                $takeId !== null && $offset > $takes[$takeId]->length => 'A shot starts past the end of its take.',
                default => null,
            };

            if ($error) {
                throw ValidationException::withMessages(["shots.{$i}" => $error]);
            }

            if ($id !== null) {
                $seen[] = $id;
            }

            $rows[] = ['id' => $id, 'copy_of' => $id === null ? $copyOf : null, 'start' => $start, 'end' => $end, 'take_id' => $takeId, 'offset' => $offset, 'motion' => $motion];
            $at = $end;
        }

        if (! $rows || abs($at - $video->duration) > 0.05) {
            throw ValidationException::withMessages(['shots' => 'Shots must cover the whole song.']);
        }

        $rows[array_key_last($rows)]['end'] = round($video->duration, 2);

        return $rows;
    }

    /**
     * The newest finished video the track's owner made for it, if any.
     */
    public function forTrack(Track $track): ?MusicVideo
    {
        $owner = $track->release?->user_id;

        if (! $owner) {
            return null;
        }

        return MusicVideo::query()
            ->where('track_id', $track->id)
            ->where('user_id', $owner)
            ->where('status', MusicVideo::READY)
            ->whereNotNull('file')
            ->latest('id')
            ->first();
    }

    /**
     * Save the video: render a new clip for each shot that needs one, then
     * stitch every shot over the song. A first render is priced by started
     * minutes of the song, a later one by the seconds of shots it renders,
     * and a save that only stitches is free. Plan video time pays before
     * credits when it covers the whole job.
     */
    public function render(MusicVideo $video): MusicVideo
    {
        $this->ensureStatus($video, self::EDITABLE);
        $this->ensureNotDrawing($video);

        $user = $video->user;
        $minutes = $video->startedMinutes();
        $shots = $video
            ->shots()
            ->with('take')
            ->get();
        $todo = $shots->filter(fn(MusicVideoShot $shot) => $shot->needsClip());
        $full = $shots->isNotEmpty() && $todo->count() === $shots->count();
        $seconds = $full ? $minutes * 60 : (int) ceil($todo->sum(fn(MusicVideoShot $shot) => $shot->length()));
        $billing = match (true) {
            $seconds === 0 => null,
            $this->secondsLeft($user) >= $seconds => MusicVideo::MINUTES,
            default => MusicVideo::CREDITS,
        };

        return DB::transaction(function () use ($video, $user, $minutes, $billing, $seconds, $full, $shots, $todo) {
            $video
                ->forceFill(['status' => MusicVideo::RENDERING, 'error' => null])
                ->save();

            $this->studio->start($user, null, 'video_render', [
                'music_video_id' => $video->id,
                'title' => $video->track?->title,
                'minutes' => $minutes,
                'billing' => $billing,
                'seconds' => $seconds,
                'full' => $full,
                'shots' => $shots->count(),
                'missing' => $todo->count(),
            ]);

            return $video;
        });
    }

    /**
     * Draw a failed storyboard again. A planned storyboard keeps its shots and
     * stills and only draws the missing ones, for that share of the price.
     */
    public function retryStoryboard(MusicVideo $video): MusicVideo
    {
        $this->ensureStatus($video, [MusicVideo::FAILED]);

        $total = $video
            ->shots()
            ->count();
        $missing = $video
            ->shots()
            ->whereNull('keyframe')
            ->count();

        return DB::transaction(function () use ($video, $total, $missing) {
            $video
                ->forceFill(['status' => MusicVideo::PLANNING, 'error' => null])
                ->save();

            $this->studio->start($video->user, null, 'video_storyboard', [
                'music_video_id' => $video->id,
                'title' => $video->track?->title,
                'minutes' => $video->startedMinutes(),
                'resume' => $total > 0,
                'shots' => $total,
                'missing' => $missing,
            ]);

            return $video;
        });
    }

    /**
     * Draw a new still for one shot from its prompt, paid by the shot's
     * length at the storyboard rate. The shot needs a new clip after.
     */
    public function redrawStill(MusicVideo $video, MusicVideoShot $shot): MusicVideoShot
    {
        $this->ensureStatus($video, self::EDITABLE);

        if ($shot->drawing) {
            throw ValidationException::withMessages(['shot' => 'A new still is already being drawn for this shot.']);
        }

        return DB::transaction(function () use ($video, $shot) {
            $shot
                ->forceFill(['drawing' => true])
                ->save();

            $this->studio->start($video->user, null, 'video_still', [
                'music_video_id' => $video->id,
                'shot_id' => $shot->id,
                'title' => $video->track?->title,
                'seconds' => $shot->length(),
            ]);

            return $shot->refresh();
        });
    }

    /**
     * Seconds of video time the user's plan has left this month, counted
     * from the renders it paid for that didn't fail.
     */
    public function secondsLeft(User $user): int
    {
        $allowance = (int) $user->feature(Feature::VIDEO_MINUTES) * 60;

        if ($allowance <= 0) {
            return 0;
        }

        $seconds = DB::getDriverName() === 'pgsql'
            ? "COALESCE((input->>'seconds')::numeric, (input->>'minutes')::numeric * 60, 0)"
            : "COALESCE(json_extract(input, '$.seconds'), json_extract(input, '$.minutes') * 60, 0)";
        $used = (int) AiJob::query()
            ->where('user_id', $user->id)
            ->where('kind', 'video_render')
            ->where('input->billing', MusicVideo::MINUTES)
            ->where('status', '!=', AiJob::FAILED)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum(DB::raw($seconds));

        return max(0, $allowance - $used);
    }

    /**
     * Delete a video and its files, unless a job is still working on it.
     */
    public function delete(MusicVideo $video): void
    {
        $this->ensureStatus($video, [MusicVideo::STORYBOARD, MusicVideo::READY, MusicVideo::FAILED]);

        $this->storage->forget($video);
        $video->delete();
    }

    /**
     * Remove the files of every video made for these tracks, before the
     * tracks are deleted and the database drops the videos with them.
     */
    public function forgetForTracks(Builder|Relation $tracks): void
    {
        $query = $tracks instanceof Relation ? $tracks->getQuery() : $tracks;
        $ids = (clone $query)
            ->withoutGlobalScopes()
            ->select('tracks.id');

        MusicVideo::query()
            ->whereIn('track_id', $ids)
            ->select('id')
            ->chunkById(100, fn($videos) => $videos->each(fn(MusicVideo $video) => $this->storage->forget($video)));
    }

    /**
     * The job working on a video now, if any.
     */
    public function activeJob(MusicVideo $video): ?AiJob
    {
        return AiJob::query()
            ->whereIn('kind', ['video_storyboard', 'video_render'])
            ->where('input->music_video_id', $video->id)
            ->unfinished()
            ->latest('id')
            ->first();
    }

    protected function ensureNotDrawing(MusicVideo $video): void
    {
        $drawing = $video
            ->shots()
            ->where('drawing', true)
            ->exists();

        if ($drawing) {
            throw ValidationException::withMessages(['video' => 'Wait for the new stills to finish drawing.']);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
    protected function ensureStatus(MusicVideo $video, array $statuses): void
    {
        if (! in_array($video->status, $statuses, true)) {
            throw ValidationException::withMessages(['video' => 'Wait for this video to finish what it\'s doing first.']);
        }
    }
}
