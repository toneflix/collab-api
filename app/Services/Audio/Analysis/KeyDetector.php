<?php

namespace App\Services\Audio\Analysis;

use App\Enums\MusicalKey;

/**
 * Estimates a recording's key by folding its spectrum into the 12 pitch
 * classes and matching that profile against every major and minor key.
 */
class KeyDetector
{
    public const FRAME = 8192;

    public const HOP = 4096;

    public const LOWEST_HZ = 55;

    public const HIGHEST_HZ = 2000;

    /**
     * How often each scale degree sounds in major and minor music, from
     * Albrecht and Shanahan's 2013 corpus study.
     */
    public const MAJOR = [0.238, 0.006, 0.111, 0.006, 0.137, 0.094, 0.016, 0.214, 0.009, 0.080, 0.008, 0.081];

    public const MINOR = [0.220, 0.006, 0.104, 0.123, 0.019, 0.103, 0.012, 0.214, 0.062, 0.022, 0.061, 0.052];

    /**
     * @param  array<int, float>  $samples  mono audio
     * @return array{key: MusicalKey, confidence: float}|null
     */
    public function detect(array $samples, int $rate): ?array
    {
        $chroma = $this->chroma($samples, $rate);
        $total = array_sum($chroma);

        if ($total <= 0) {
            return null;
        }

        $scores = [];
        foreach ([false, true] as $minor) {
            $profile = $minor ? self::MINOR : self::MAJOR;

            for ($tonic = 0; $tonic < 12; $tonic++) {
                $rotated = [];
                for ($i = 0; $i < 12; $i++) {
                    $rotated[$i] = $profile[($i - $tonic + 12) % 12];
                }

                $scores[] = [
                    'key' => MusicalKey::fromPitch($tonic, $minor),
                    'score' => $this->correlation($chroma, $rotated),
                ];
            }
        }

        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        [$best, $next] = $scores;

        if ($best['score'] <= 0.2) {
            return null;
        }

        return [
            'key' => $best['key'],
            'confidence' => round(max(0, min(1, $best['score'] * 0.5 + ($best['score'] - $next['score']) * 5)), 3),
        ];
    }

    /**
     * Total energy in each pitch class, C first, across all frames. Only
     * spectral peaks count, so drums and noise add little.
     *
     * @param  array<int, float>  $samples
     * @return list<float>
     */
    public function chroma(array $samples, int $rate): array
    {
        $peaks = $this->peaks($samples, $rate);
        $tuning = $this->tuning($peaks);
        $chroma = array_fill(0, 12, 0.0);

        foreach ($peaks as $frame) {
            $classes = array_fill(0, 12, 0.0);

            foreach ($frame as [$midi, $magnitude]) {
                $classes[(((int) round($midi - $tuning)) % 12 + 12) % 12] += $magnitude;
            }

            $loudest = max($classes);
            if ($loudest <= 0) {
                continue;
            }

            foreach ($classes as $pitch => $value) {
                $chroma[$pitch] += $value / $loudest;
            }
        }

        return $chroma;
    }

    /**
     * Each frame's spectral peaks in the harmonic range, as fractional MIDI
     * notes with their magnitudes.
     *
     * @param  array<int, float>  $samples
     * @return list<list<array{0: float, 1: float}>>
     */
    protected function peaks(array $samples, int $rate): array
    {
        $fft = new Fft(self::FRAME);
        $hz = $rate / self::FRAME;
        $from = (int) ceil(self::LOWEST_HZ / $hz);
        $to = (int) floor(min(self::HIGHEST_HZ, $rate / 2 - $hz) / $hz);
        $frames = intdiv(max(0, count($samples) - self::FRAME), self::HOP) + 1;
        $peaks = [];

        for ($f = 0; $f < $frames; $f++) {
            $spectrum = $fft->magnitudes($samples, $f * self::HOP);
            $frame = [];

            for ($bin = $from; $bin <= $to; $bin++) {
                [$left, $peak, $right] = [$spectrum[$bin - 1], $spectrum[$bin], $spectrum[$bin + 1]];

                if ($peak <= $left || $peak < $right) {
                    continue;
                }

                $curve = $left - 2 * $peak + $right;
                $shift = $curve < 0 ? 0.5 * ($left - $right) / $curve : 0;
                $frame[] = [69 + 12 * log(($bin + $shift) * $hz / 440, 2), $peak];
            }

            $peaks[] = $frame;
        }

        return $peaks;
    }

    /**
     * How far, in semitones, the recording sits from A440 tuning.
     *
     * @param  list<list<array{0: float, 1: float}>>  $peaks
     */
    protected function tuning(array $peaks): float
    {
        $x = $y = 0.0;

        foreach ($peaks as $frame) {
            foreach ($frame as [$midi, $magnitude]) {
                $angle = 2 * M_PI * ($midi - round($midi));
                $x += $magnitude * cos($angle);
                $y += $magnitude * sin($angle);
            }
        }

        return atan2($y, $x) / (2 * M_PI);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    protected function correlation(array $a, array $b): float
    {
        $meanA = array_sum($a) / 12;
        $meanB = array_sum($b) / 12;
        $top = $sa = $sb = 0.0;

        for ($i = 0; $i < 12; $i++) {
            $top += ($a[$i] - $meanA) * ($b[$i] - $meanB);
            $sa += ($a[$i] - $meanA) ** 2;
            $sb += ($b[$i] - $meanB) ** 2;
        }

        return $sa > 0 && $sb > 0 ? $top / sqrt($sa * $sb) : 0.0;
    }
}
