<?php

namespace App\Services\Ai\Video;

use App\Jobs\CutShort;
use App\Models\MusicVideo;
use App\Models\MusicVideoShort;
use App\Services\Video\VideoFiles;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Vertical shorts cut from finished music videos. Cutting is free and runs
 * on the queue.
 */
class VideoShorts
{
    public function __construct(
        protected VideoStorage $storage,
        protected VideoFiles $files,
    ) {}

    /**
     * Start cutting a short from `$start` to `$end` seconds of the video,
     * with the frame at `$focus` across it.
     */
    public function create(MusicVideo $video, float $start, float $end, float $focus = 0.5): MusicVideoShort
    {
        if ($video->status !== MusicVideo::READY || ! $video->file) {
            throw ValidationException::withMessages(['video' => 'Shorts are cut from a finished video.']);
        }

        $count = $video
            ->shorts()
            ->count();

        if ($count >= MusicVideoShort::MAX) {
            throw ValidationException::withMessages(['video' => 'A video keeps up to ' . MusicVideoShort::MAX . ' shorts. Delete one first.']);
        }

        $start = round(max(0, $start), 2);
        $end = round(min($end, $video->duration), 2);
        $length = $end - $start;

        if ($length < MusicVideoShort::MIN_SECONDS - 0.01 || $length > MusicVideoShort::MAX_SECONDS + 0.01) {
            throw ValidationException::withMessages([
                'end' => 'A short runs ' . MusicVideoShort::MIN_SECONDS . ' to ' . MusicVideoShort::MAX_SECONDS . ' seconds.',
            ]);
        }

        $short = $video
            ->shorts()
            ->create([
                'start' => $start,
                'end' => $end,
                'focus' => round(min(1, max(0, $focus)), 3),
            ]);

        CutShort::dispatch($short);

        return $short->refresh();
    }

    /**
     * Cut the short out of the finished video and store it.
     */
    public function cut(MusicVideoShort $short): void
    {
        $video = $short->video;
        $source = $this->storage->local($video?->file);
        $out = null;

        if (! $source) {
            throw new RuntimeException('The finished video is gone.');
        }

        try {
            $out = $this->files->vertical($source, $short->start, $short->length(), $short->focus, MusicVideoShort::SIZE);
            $file = $this->storage->put($video, $out, 'short', 'mp4');

            $short
                ->forceFill(['status' => MusicVideoShort::READY, 'file' => $file, 'error' => null])
                ->save();
        } finally {
            @unlink($source);

            if ($out) {
                @unlink($out);
            }
        }
    }

    public function fail(MusicVideoShort $short, Throwable $error): void
    {
        report($error);

        $short
            ->forceFill(['status' => MusicVideoShort::FAILED, 'error' => 'The short couldn\'t be cut. Try again.'])
            ->save();
    }

    /**
     * Delete a short and its file, unless it is still being cut.
     */
    public function delete(MusicVideoShort $short): void
    {
        if ($short->status === MusicVideoShort::CUTTING) {
            throw ValidationException::withMessages(['short' => 'Wait for this short to finish cutting.']);
        }

        $this->storage->delete($short->file);
        $short->delete();
    }
}
