<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * Reads a file's SHA-256 hash and, when FFmpeg was built with Chromaprint,
 * its acoustic fingerprint: one 32 bit code for about every 0.12 seconds of
 * audio. Codes survive re-encoding, so copies match even as other files.
 */
class Fingerprinter
{
    /** How much audio to fingerprint, from the start, in seconds */
    public const SECONDS = 180;

    public function ffmpeg(): string
    {
        return config('laravel-ffmpeg.ffmpeg.binaries') ?: 'ffmpeg';
    }

    /**
     * Whether FFmpeg can make Chromaprint fingerprints here.
     */
    public function available(): bool
    {
        return Cache::rememberForever('audio::chromaprint', function () {
            $process = new Process([$this->ffmpeg(), '-hide_banner', '-muxers']);

            try {
                $process->run();
            } catch (\Throwable) {
                return false;
            }

            return $process->isSuccessful() && str_contains($process->getOutput(), 'chromaprint');
        });
    }

    public function hash(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /**
     * The file's Chromaprint codes, or none when they can't be read.
     *
     * @return array<int, int>
     */
    public function codes(string $path): array
    {
        if (! $this->available()) {
            return [];
        }

        $process = new Process([
            $this->ffmpeg(), '-hide_banner', '-loglevel', 'error',
            '-t', (string) self::SECONDS, '-i', $path,
            '-f', 'chromaprint', '-fp_format', 'raw', '-',
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || $process->getOutput() === '') {
            return [];
        }

        return array_values(unpack('V*', $process->getOutput()) ?: []);
    }
}
