<?php

namespace App\Services\Ai\Video;

use App\Enums\HttpStatus;
use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A music video's files on the uploads disk: its portrait, the stills and
 * clips of its shots, and the finished video.
 */
class VideoStorage
{
    public const DIRECTORY = 'ai/videos';

    /** Hours a signed link to a file works */
    protected const LINK_HOURS = 24;

    /**
     * Copy a local file into the video's folder and return its path on the disk.
     */
    public function put(MusicVideo $video, string $local, string $name, string $ext): string
    {
        $file = self::DIRECTORY . "/{$video->id}/{$name}-" . Str::random(8) . ".{$ext}";
        $stream = fopen($local, 'r');

        $this
            ->disk()
            ->writeStream($file, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $file;
    }

    /**
     * Copy a file on the disk to a local temporary file, or null when it's gone.
     */
    public function local(?string $file): ?string
    {
        $disk = $this->disk();

        if (! $file || ! $disk->exists($file)) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'video-');
        $stream = $disk->readStream($file);

        file_put_contents($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    public function delete(?string $file): void
    {
        if ($file) {
            $this
                ->disk()
                ->delete($file);
        }
    }

    /**
     * Remove every file of a video.
     */
    public function forget(MusicVideo $video): void
    {
        $this
            ->disk()
            ->deleteDirectory(self::DIRECTORY . "/{$video->id}");
    }

    /**
     * A link to the finished video: straight from the bucket when the disk
     * can sign one, so players can seek, else through the API.
     */
    public function videoUrl(MusicVideo $video): ?string
    {
        if (! $video->file) {
            return null;
        }

        return $this->signed($video->file, 'ai.videos.file', ['video' => $video->id]);
    }

    public function keyframeUrl(MusicVideoShot $shot): ?string
    {
        if (! $shot->keyframe) {
            return null;
        }

        return $this->signed($shot->keyframe, 'ai.videos.keyframe', ['video' => $shot->music_video_id, 'shot' => $shot->id]);
    }

    /**
     * @param  array<string, int>  $parameters
     */
    protected function signed(string $file, string $route, array $parameters): string
    {
        $expires = now()->addHours(self::LINK_HOURS);
        $disk = $this->disk();

        return rescue(
            fn() => $disk->temporaryUrl($file, $expires),
            fn() => URL::temporarySignedRoute($route, $expires, $parameters),
            false,
        );
    }

    public function stream(?string $file, string $type, ?string $download = null): StreamedResponse
    {
        abort_unless($file && $this->disk()->exists($file), HttpStatus::NOT_FOUND->value, 'That file is gone.');

        return $this
            ->disk()
            ->response($file, $download, ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600']);
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.default'));
    }
}
