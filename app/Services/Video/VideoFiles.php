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
     * An uploaded clip as a silent H.264 MP4 at the size and frame rate of
     * the video, at most `$max` seconds long.
     *
     * @param  array{0: int, 1: int}  $size
     */
    public function normalize(string $clip, array $size, float $max): string
    {
        [$width, $height] = $size;
        $out = $this->temp();

        $this->run([
            '-i', $clip,
            '-t', number_format($max, 3, '.', ''),
            '-vf', 'fps=' . self::FPS . ",scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1",
            '-an',
            ...$this->videoEncoding(),
            '-movflags', '+faststart',
            $out,
        ], $out);

        return $out;
    }

    /**
     * Seconds a video file runs.
     */
    public function length(string $file): float
    {
        $process = new Process([
            config('laravel-ffmpeg.ffprobe.binaries') ?: 'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $file,
        ]);
        $process->run();

        $seconds = (float) trim($process->getOutput());

        if (! $process->isSuccessful() || $seconds <= 0) {
            throw new RuntimeException('The clip couldn\'t be read.');
        }

        return round($seconds, 2);
    }

    /**
     * Parts cut one after another, each cropped to the size and trimmed or
     * held on its last frame to its length, with the song as the only sound.
     * Each part is cut on its own first, so memory stays flat however many
     * there are, then the cuts are joined without encoding again. Frame counts
     * come from where each part starts and ends on the song, and each cut is
     * listed with its exact length, so every cut lands within half a frame of
     * its place however long the video runs.
     *
     * @param  list<VideoPart>  $parts
     * @param  array{0: int, 1: int}  $size
     */
    public function stitch(array $parts, string $audio, array $size): string
    {
        $out = $this->temp();
        $cuts = [];
        $lines = [];
        $total = 0.0;

        try {
            foreach ($parts as $part) {
                $frames = max(1, $this->frame($total + $part->length) - $this->frame($total));
                $cut = $this->cut($part, $frames, $size);
                $cuts[] = $cut;
                $lines[] = "file '" . str_replace("'", "'\\''", $cut) . "'";
                $lines[] = 'duration ' . number_format($frames / self::FPS, 6, '.', '');
                $total += $part->length;
            }

            $list = tempnam(sys_get_temp_dir(), 'concat-');

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
     * One part encoded on its own at the size and frame rate of the video,
     * `$frames` long.
     *
     * @param  array{0: int, 1: int}  $size
     */
    protected function cut(VideoPart $part, int $frames, array $size): string
    {
        [$width, $height] = $size;
        $seconds = number_format($part->length, 3, '.', '');
        $fit = "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1";
        $cut = $this->temp();

        if ($part->isStill()) {
            $input = ['-i', $part->path];
            $filter = 'scale=' . ($width * 2) . ':' . ($height * 2) . ':force_original_aspect_ratio=increase,crop=' . ($width * 2) . ':' . ($height * 2) . ','
                . $this->motion($part->motion, $frames, $width, $height) . ',setsar=1';
        } else {
            $input = ['-ss', number_format($part->offset, 3, '.', ''), '-i', $part->path];
            $filter = 'fps=' . self::FPS . ",{$fit},tpad=stop_mode=clone:stop_duration={$seconds},setpts=PTS-STARTPTS";
        }

        $this->run([
            ...$input,
            '-vf', $filter,
            '-an',
            '-frames:v', (string) $frames,
            ...$this->videoEncoding(),
            $cut,
        ], $cut);

        return $cut;
    }

    /**
     * A zoompan filter that moves over a still for a number of frames.
     */
    protected function motion(?string $motion, int $frames, int $width, int $height): string
    {
        $last = max(1, $frames - 1);
        $centre = "x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'";
        $move = match ($motion) {
            VideoPart::ZOOM_OUT => "z='1.2-0.2*on/{$last}':{$centre}",
            VideoPart::PAN => "z='1.15':x='(iw-iw/zoom)*on/{$last}':y='(ih-ih/zoom)/2'",
            default => "z='1+0.2*on/{$last}':{$centre}",
        };

        return "zoompan={$move}:d={$frames}:s={$width}x{$height}:fps=" . self::FPS;
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
