<?php

namespace App\Services\Ai\Video;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Audio\TrackAudio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
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

        $this->takes->prune($shot);

        return $shot;
    }

    /**
     * Put the shots in a new order. The time slots stay where they are, so
     * each shot takes the slot of its new place. B-roll keeps its take; a
     * singing shot moved off the lines its take was synced to needs a new one.
     *
     * @param  list<int>  $order  every shot id of the video, in the new order
     */
    public function reorderShots(MusicVideo $video, array $order): MusicVideo
    {
        $this->ensureStatus($video, self::EDITABLE);

        $shots = $video
            ->shots()
            ->orderBy('position')
            ->get();
        $ids = $shots
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $sent = collect($order)
            ->map(fn($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($ids !== $sent) {
            throw ValidationException::withMessages(['shots' => 'Send every shot of this video once.']);
        }

        $slots = $shots
            ->map(fn(MusicVideoShot $shot) => [$shot->start, $shot->end])
            ->values()
            ->all();

        DB::transaction(function () use ($video, $order, $slots) {
            // Positions are unique per video, so move them all out of the way first.
            $video
                ->shots()
                ->increment('position', 1000);

            foreach (array_values($order) as $i => $id) {
                [$start, $end] = $slots[$i];

                MusicVideoShot::query()
                    ->whereKey((int) $id)
                    ->update(['position' => $i + 1, 'start' => $start, 'end' => $end]);
            }

            $video
                ->forceFill(['edited_at' => now()])
                ->save();
        });

        return $video;
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
