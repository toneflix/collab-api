<?php

namespace App\Services\Audio\Analysis;

use App\Services\Audio\Fingerprinter;
use Symfony\Component\Process\Process;

/**
 * Detects a recording's tempo and key. FFmpeg decodes a stretch from the
 * middle of the file to mono PCM, then the detectors work on the samples.
 */
class AudioAnalyzer
{
    public const RATE = 11025;

    /** How much audio to analyse, in seconds */
    public const SECONDS = 180;

    public function __construct(
        protected TempoDetector $tempo,
        protected KeyDetector $key,
        protected Fingerprinter $fingerprinter,
    ) {}

    public function analyze(string $path, float $duration = 0): ?AudioTags
    {
        $samples = $this->decode($path, $duration);

        if (count($samples) < self::RATE * 5) {
            return null;
        }

        $tempo = $this->tempo->detect($samples, self::RATE);
        $key = $this->key->detect($samples, self::RATE);

        if (! $tempo && ! $key) {
            return null;
        }

        return new AudioTags(
            bpm: $tempo['bpm'] ?? null,
            key: $key['key'] ?? null,
            bpmConfidence: $tempo['confidence'] ?? 0,
            keyConfidence: $key['confidence'] ?? 0,
        );
    }

    /**
     * Mono samples from the middle of the file, skipping intros on longer songs.
     *
     * @return list<float>
     */
    public function decode(string $path, float $duration = 0): array
    {
        $start = $duration > self::SECONDS + 30 ? min(30.0, ($duration - self::SECONDS) / 2) : 0.0;

        $process = new Process([
            $this->fingerprinter->ffmpeg(), '-hide_banner', '-loglevel', 'error',
            '-ss', (string) $start, '-t', (string) self::SECONDS, '-i', $path,
            '-ac', '1', '-ar', (string) self::RATE, '-f', 'f32le', '-',
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || $process->getOutput() === '') {
            return [];
        }

        return array_values(unpack('g*', $process->getOutput()) ?: []);
    }
}
