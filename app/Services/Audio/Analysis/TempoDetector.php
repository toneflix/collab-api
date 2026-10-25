<?php

namespace App\Services\Audio\Analysis;

/**
 * Estimates a recording's tempo from how often its onsets repeat: a spectral
 * flux onset curve, its autocorrelation weighted toward common tempos, then
 * a finer search around the best match.
 */
class TempoDetector
{
    public const FRAME = 512;

    public const HOP = 128;

    public const MIN_BPM = 60;

    public const MAX_BPM = 200;

    /**
     * @param  array<int, float>  $samples  mono audio
     * @return array{bpm: float, confidence: float}|null
     */
    public function detect(array $samples, int $rate): ?array
    {
        $fps = $rate / self::HOP;
        $onsets = $this->onsets($samples, $rate);

        if (count($onsets) < $fps * 10) {
            return null;
        }

        $minLag = (int) floor(60 * $fps / self::MAX_BPM);
        $maxLag = (int) ceil(60 * $fps / self::MIN_BPM) * 4;
        $acf = $this->autocorrelation($onsets, $maxLag + 2);

        if ($acf[0] <= 0) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        $scores = [];

        for ($bpm = self::MIN_BPM; $bpm <= self::MAX_BPM; $bpm += 0.5) {
            $score = $this->comb($acf, 60 * $fps / $bpm) * $this->prior($bpm);
            $scores[] = $score;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $bpm;
            }
        }

        if ($best === null) {
            return null;
        }

        for ($bpm = $best - 0.5; $bpm <= $best + 0.5; $bpm += 0.05) {
            $score = $this->comb($acf, 60 * $fps / $bpm) * $this->prior($bpm);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $bpm;
            }
        }

        sort($scores);
        $median = $scores[intdiv(count($scores), 2)] ?: 1e-9;

        return [
            'bpm' => abs($best - round($best)) < 0.3 ? round($best) : round($best, 1),
            'confidence' => round(max(0, min(1, 1 - $median / $bestScore)), 3),
        ];
    }

    /**
     * How much onset energy rises in each frame, summed over log spaced bands.
     *
     * @param  array<int, float>  $samples
     * @return list<float>
     */
    public function onsets(array $samples, int $rate): array
    {
        $fft = new Fft(self::FRAME);
        $bands = $this->bands($rate);
        $frames = intdiv(max(0, count($samples) - self::FRAME), self::HOP);

        $previous = null;
        $flux = [];

        for ($f = 0; $f < $frames; $f++) {
            $spectrum = $fft->magnitudes($samples, $f * self::HOP);
            $energy = [];

            foreach ($bands as $b => [$from, $to]) {
                $sum = 0.0;
                for ($i = $from; $i < $to; $i++) {
                    $sum += $spectrum[$i];
                }
                $energy[$b] = log(1 + 100 * $sum);
            }

            $rise = 0.0;
            if ($previous !== null) {
                foreach ($energy as $b => $value) {
                    $rise += max(0, $value - $previous[$b]);
                }
            }

            $flux[] = $rise;
            $previous = $energy;
        }

        return $this->detrend($flux, (int) round($rate / self::HOP));
    }

    /**
     * Frequency bins grouped into bands that widen with pitch, up to 5 kHz.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function bands(int $rate): array
    {
        $hz = $rate / self::FRAME;
        $top = min(5000, $rate / 2);
        $edges = [];

        for ($i = 0; $i <= 24; $i++) {
            $edges[] = (int) round(40 * ($top / 40) ** ($i / 24) / $hz);
        }

        $bands = [];
        for ($i = 0; $i < 24; $i++) {
            $from = $edges[$i];
            $to = max($from + 1, $edges[$i + 1]);
            $bands[] = [$from, min($to, intdiv(self::FRAME, 2) + 1)];
        }

        return $bands;
    }

    /**
     * Removes the running average so only sudden rises are left.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    protected function detrend(array $values, int $width): array
    {
        $count = count($values);
        $result = [];
        $half = intdiv($width, 2);

        $prefix = [0.0];
        foreach ($values as $i => $value) {
            $prefix[$i + 1] = $prefix[$i] + $value;
        }

        for ($i = 0; $i < $count; $i++) {
            $from = max(0, $i - $half);
            $to = min($count, $i + $half + 1);
            $mean = ($prefix[$to] - $prefix[$from]) / ($to - $from);
            $result[] = max(0, $values[$i] - $mean);
        }

        return $result;
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    protected function autocorrelation(array $values, int $maxLag): array
    {
        $count = count($values);
        $acf = [];

        for ($lag = 0; $lag <= $maxLag; $lag++) {
            $sum = 0.0;
            for ($i = $lag; $i < $count; $i++) {
                $sum += $values[$i] * $values[$i - $lag];
            }
            $acf[$lag] = $sum / max(1, $count - $lag);
        }

        return $acf;
    }

    /**
     * How strongly the onsets repeat at a beat period and its first multiples.
     *
     * @param  list<float>  $acf
     */
    protected function comb(array $acf, float $period): float
    {
        $score = 0.0;

        foreach ([1 => 1.0, 2 => 0.5, 3 => 0.33, 4 => 0.25] as $multiple => $weight) {
            $score += $weight * $this->at($acf, $period * $multiple);
        }

        return $score;
    }

    /**
     * @param  list<float>  $acf
     */
    protected function at(array $acf, float $lag): float
    {
        $i = (int) floor($lag);
        $fraction = $lag - $i;

        return ($acf[$i] ?? 0.0) * (1 - $fraction) + ($acf[$i + 1] ?? 0.0) * $fraction;
    }

    /**
     * Favours tempos near 120 BPM, falling off by octave, to settle whether a
     * song is at a tempo or at half or double of it.
     */
    protected function prior(float $bpm): float
    {
        return exp(-0.5 * (log($bpm / 120, 2) / 0.9) ** 2);
    }
}
