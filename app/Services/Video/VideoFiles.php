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

    /** Frame rate of stitched videos */
    public const FPS = 24;

    /**
     * The clip with this audio as its only sound, cut to the shorter of the
     * two, as an H.264/AAC MP4 that can start playing before it downloads.
     */
    public function withAudio(string $video, string $audio): string
    {
        $out = $this->temp();

        $this->run([
            '-i', $video, '-i', $audio,
            '-map', '0:v:0', '-map', '1:a:0',
            ...$this->encoding(),
            '-shortest',
            $out,
        ], $out);

        return $out;
    }

    /**
     * Clips cut one after another, each cropped to the size and trimmed or held
     * on its last frame to its length, with the song as the only sound. Each
     * clip is cut on its own first, so memory stays flat however many there
     * are, then the cuts are joined without encoding again. Frame counts come
     * from where each clip starts and ends on the song, so every cut lands
     * within half a frame of its place however long the video runs.
     *
     * @param  list<array{0: string, 1: float}>  $clips  Path and length in seconds
     * @param  array{0: int, 1: int}  $size
     */
    public function stitch(array $clips, string $audio, array $size): string
    {
        [$width, $height] = $size;
        $out = $this->temp();
        $cuts = [];
        $total = 0.0;

        try {
            foreach (array_values($clips) as [$path, $length]) {
                $frames = max(1, $this->frame($total + $length) - $this->frame($total));
                $seconds = number_format($length, 3, '.', '');
                $cut = $this->temp();

                $this->run([
                    '-i', $path,
                    '-vf', 'fps=' . self::FPS . ",scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1,"
                        . "tpad=stop_mode=clone:stop_duration={$seconds},setpts=PTS-STARTPTS",
                    '-an',
                    '-frames:v', (string) $frames,
                    ...$this->videoEncoding(),
                    $cut,
                ], $cut);

                $cuts[] = $cut;
                $total += $length;
            }

            $list = tempnam(sys_get_temp_dir(), 'concat-');
            $lines = array_map(fn(string $cut) => "file '" . str_replace("'", "'\\''", $cut) . "'", $cuts);

            file_put_contents($list, implode("\n", $lines) . "\n");

            try {
                $this->run([
                    '-f', 'concat', '-safe', '0', '-i', $list,
                    '-i', $audio,
                    '-map', '0:v:0', '-map', '1:a:0',
                    '-c:v', 'copy',
                    '-c:a', 'aac', '-b:a', '256k',
                    '-movflags', '+faststart',
                    '-t', number_format($total, 3, '.', ''),
                    $out,
                ], $out);
            } finally {
                @unlink($list);
            }
        } finally {
            array_map(fn(string $cut) => @unlink($cut), $cuts);
        }

        return $out;
    }

    /**
     * The frame of the stitched video that shows this second of the song.
     */
    protected function frame(float $seconds): int
    {
        return (int) round($seconds * self::FPS);
    }

    /**
     * @return list<string>
     */
    protected function encoding(): array
    {
        return [
            ...$this->videoEncoding(),
            '-c:a', 'aac', '-b:a', '256k',
            '-movflags', '+faststart',
        ];
    }

    /**
     * H.264 settings shared by every cut, so cuts join without encoding again.
     * Two threads keep FFmpeg's memory small on the worker.
     *
     * @return list<string>
     */
    protected function videoEncoding(): array
    {
        return ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-threads', '2'];
    }

    /**
     * @param  list<string>  $args
     */
    protected function run(array $args, string $out): void
    {
        $process = new Process([$this->audio->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y', ...$args]);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            @unlink($out);

            throw new RuntimeException('FFmpeg failed: ' . trim($process->getErrorOutput()));
        }
    }

    protected function temp(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'video-');
        @unlink($base);

        return "{$base}.mp4";
    }
}
