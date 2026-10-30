<?php

namespace App\Services\Ai\Video;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Audio\TrackAudio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use Toneflix\LaravelFileable\Media;

/**
 * Makes music videos for released tracks: starts the storyboard, lets the
 * owner change shots, then starts the render, paid from the plan's video
 * minutes when it has enough, else from AI credits.
 */
class MusicVideos
{
    public function __construct(
        protected AiStudio $studio,
        protected TrackAudio $audio,
        protected VideoStorage $storage,
    ) {}

    /**
     * Start a video for the track and draw its storyboard. A photo is only
     * taken from plans that allow the owner's own photos.
     *
     * @param  array{ratio?: string, brief?: ?string}  $options
     */
    public function create(User $user, Track $track, array $options, ?UploadedFile $photo = null): MusicVideo
    {
        if ($photo && ! $user->feature(Feature::VIDEO_OWN_PHOTOS)) {
            throw ValidationException::withMessages(['photo' => 'Your plan doesn\'t include music videos from your own photos.']);
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
            $this->keepPortrait($video, $user, $track, $photo);

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
     * Change what a shot shows before the video is rendered.
     */
    public function updateShot(MusicVideo $video, MusicVideoShot $shot, string $prompt): MusicVideoShot
    {
        $this->ensureStatus($video, [MusicVideo::STORYBOARD]);

        $shot
            ->forceFill(['prompt' => $prompt, 'clip' => null])
            ->save();

        return $shot;
    }

    /**
     * Start rendering the storyboard into the finished video.
     */
    public function render(MusicVideo $video): MusicVideo
    {
        $this->ensureStatus($video, [MusicVideo::STORYBOARD]);

        $user = $video->user;
        $minutes = $video->startedMinutes();
        $billing = $this->minutesLeft($user) >= $minutes ? MusicVideo::MINUTES : MusicVideo::CREDITS;

        return DB::transaction(function () use ($video, $user, $minutes, $billing) {
            $video
                ->forceFill([
                    'status' => MusicVideo::RENDERING,
                    'billing' => $billing,
                    'minutes' => $billing === MusicVideo::MINUTES ? $minutes : 0,
                    'billed_at' => now(),
                    'error' => null,
                ])
                ->save();

            $this->studio->start($user, null, 'video_render', [
                'music_video_id' => $video->id,
                'title' => $video->track?->title,
                'minutes' => $minutes,
                'billing' => $billing,
            ]);

            return $video;
        });
    }

    /**
     * Video minutes the user's plan has left this month.
     */
    public function minutesLeft(User $user): int
    {
        $allowance = (int) $user->feature(Feature::VIDEO_MINUTES);

        if ($allowance <= 0) {
            return 0;
        }

        $used = (int) MusicVideo::query()
            ->billedInMinutesThisMonth($user)
            ->sum('minutes');

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
