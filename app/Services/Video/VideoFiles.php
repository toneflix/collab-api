<?php

namespace App\Services\Video;

use App\Services\Audio\StemAudio;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * FFmpeg work on generated video clips.
 */
class VideoFiles
{
    public function __construct(protected StemAudio $audio) {}

    /**
     * The clip with this audio as its only sound, cut to the shorter of the
     * two, as an H.264/AAC MP4 that can start playing before it downloads.
     */
    public function withAudio(string $video, string $audio): string
    {
        $base = tempnam(sys_get_temp_dir(), 'video-');
        @unlink($base);
        $out = "{$base}.mp4";

        $process = new Process([
            $this->audio->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $video, '-i', $audio,
            '-map', '0:v:0', '-map', '1:a:0',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '256k',
            '-shortest', '-movflags', '+faststart',
            $out,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            @unlink($out);

            throw new RuntimeException('FFmpeg failed: ' . trim($process->getErrorOutput()));
        }

        return $out;
    }
}
