<?php

namespace App\Services\Audio;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * FFmpeg work on separated stems: rebuilding the part of a song the other
 * stems leave out, and encoding stems once at a high bitrate.
 */
class StemAudio
{
    /** Bitrate stems are stored at, in kbps */
    public const BITRATE = 320;

    /** Sample rate the remainder is worked out at */
    protected const RATE = 44100;

    public function ffmpeg(): string
    {
        return config('laravel-ffmpeg.ffmpeg.binaries') ?: 'ffmpeg';
    }

    /**
     * The song minus the given parts, as a 32 bit float WAV, so the parts and
     * the remainder add up to exactly the song.
     *
     * @param  list<string>  $parts
     */
    public function remainder(string $song, array $parts): string
    {
        $out = $this->temp('wav');
        $inputs = ['-i', $song];
        $chains = ["[0:a]{$this->format()}[s]"];
        $mix = '[s]';

        foreach (array_values($parts) as $i => $part) {
            $n = $i + 1;
            $inputs = [...$inputs, '-i', $part];
            $chains[] = "[{$n}:a]{$this->format()},volume=-1[p{$n}]";
            $mix .= "[p{$n}]";
        }

        $count = count($parts) + 1;
        $chains[] = "{$mix}amix=inputs={$count}:normalize=0:duration=first:dropout_transition=0[out]";

        $this->run([
            ...$inputs,
            '-filter_complex', implode(';', $chains),
            '-map', '[out]', '-c:a', 'pcm_f32le', $out,
        ], $out);

        return $out;
    }

    /**
     * Encode audio as a 320 kbps MP3.
     */
    public function mp3(string $path): string
    {
        $out = $this->temp('mp3');

        $this->run(['-i', $path, '-c:a', 'libmp3lame', '-b:a', self::BITRATE.'k', $out], $out);

        return $out;
    }

    /**
     * The file's RMS level in dBFS, or null when it can't be read.
     */
    public function level(string $path): ?float
    {
        $process = new Process([
            $this->ffmpeg(), '-hide_banner', '-nostats', '-i', $path,
            '-af', 'astats=measure_perchannel=none:measure_overall=RMS_level', '-f', 'null', '-',
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! preg_match('/RMS level dB:\s*(-?[\d.]+|-inf)/', $process->getErrorOutput(), $match)) {
            return null;
        }

        return $match[1] === '-inf' ? -INF : (float) $match[1];
    }

    protected function format(): string
    {
        return 'aresample='.self::RATE.',aformat=sample_fmts=fltp:channel_layouts=stereo';
    }

    /**
     * @param  list<string>  $args
     */
    protected function run(array $args, string $out): void
    {
        $process = new Process([$this->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y', ...$args]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            @unlink($out);

            throw new RuntimeException('FFmpeg failed: '.trim($process->getErrorOutput()));
        }
    }

    protected function temp(string $ext): string
    {
        $base = tempnam(sys_get_temp_dir(), 'stem-');
        @unlink($base);

        return "{$base}.{$ext}";
    }
}
