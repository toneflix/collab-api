<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideoShot;

/**
 * Turns the shots a planner suggested into a clean cut list for the whole
 * song: cuts on the beat, no gaps or overlaps, and every shot between the
 * shortest and longest length a model renders well.
 */
class ShotList
{
    /** Shortest shot, in seconds */
    public const MIN_SECONDS = 2.0;

    /** Most shots one video can have */
    public const MAX_SHOTS = 120;

    /**
     * @param  float  $maxSeconds  Longest shot, in seconds
     */
    public function __construct(
        protected float $duration,
        protected ?float $bpm,
        protected float $maxSeconds = 8.0,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $planned  Shots with start, kind and prompt
     * @return list<array{start: float, end: float, kind: string, prompt: string}>
     */
    public function build(array $planned): array
    {
        $planned = collect($planned)
            ->filter(fn($shot) => is_array($shot) && filled($shot['prompt'] ?? null))
            ->map(fn(array $shot) => [
                'start' => max(0.0, (float) ($shot['start'] ?? 0)),
                'kind' => ($shot['kind'] ?? null) === MusicVideoShot::PERFORMANCE ? MusicVideoShot::PERFORMANCE : MusicVideoShot::BROLL,
                'prompt' => str((string) $shot['prompt'])
                    ->squish()
                    ->limit(600)
                    ->toString(),
            ])
            ->sortBy('start')
            ->values();

        if ($planned->isEmpty() || $this->duration <= 0) {
            return [];
        }

        $cuts = $this->split($this->merge($this->snap($planned->pluck('start')->all())));
        $bounds = [0.0, ...$cuts, round($this->duration, 2)];
        $tolerance = $this->bpm > 0 ? 30 / $this->bpm + 0.01 : 0.01;
        $shots = [];

        // Each shot takes the planned shot that starts with it, or carries on
        // the one before when it was split off a long shot.
        for ($i = 0; $i < count($bounds) - 1 && count($shots) < self::MAX_SHOTS; $i++) {
            $start = $bounds[$i];
            $end = $i === count($bounds) - 2 || count($shots) === self::MAX_SHOTS - 1 ? round($this->duration, 2) : $bounds[$i + 1];
            $source = $planned
                ->filter(fn(array $shot) => $shot['start'] <= $start + $tolerance)
                ->last() ?? $planned->first();

            $shots[] = ['start' => $start, 'end' => $end, 'kind' => $source['kind'], 'prompt' => $source['prompt']];
        }

        return $shots;
    }

    /**
     * Cut times moved onto the nearest beat, inside the song.
     *
     * @param  list<float>  $starts
     * @return list<float>
     */
    protected function snap(array $starts): array
    {
        $beat = $this->bpm > 0 ? 60 / $this->bpm : null;

        return collect($starts)
            ->map(fn(float $time) => $beat ? round($time / $beat) * $beat : $time)
            ->map(fn(float $time) => round($time, 2))
            ->filter(fn(float $time) => $time >= self::MIN_SECONDS && $time <= $this->duration - self::MIN_SECONDS)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Drop cuts that would leave a shot shorter than the minimum.
     *
     * @param  list<float>  $cuts
     * @return list<float>
     */
    protected function merge(array $cuts): array
    {
        $kept = [];
        $last = 0.0;

        foreach ($cuts as $cut) {
            if ($cut - $last >= self::MIN_SECONDS) {
                $kept[] = $cut;
                $last = $cut;
            }
        }

        return $kept;
    }

    /**
     * Add even cuts, on the beat where possible, to shots longer than the maximum.
     *
     * @param  list<float>  $cuts
     * @return list<float>
     */
    protected function split(array $cuts): array
    {
        $bounds = [0.0, ...$cuts, $this->duration];
        $beat = $this->bpm > 0 ? 60 / $this->bpm : null;
        $result = [];

        for ($i = 0; $i < count($bounds) - 1; $i++) {
            [$start, $end] = [$bounds[$i], $bounds[$i + 1]];
            $pieces = (int) ceil(($end - $start) / $this->maxSeconds);

            if ($i > 0) {
                $result[] = $start;
            }

            for ($p = 1; $p < $pieces; $p++) {
                $time = $start + ($end - $start) * $p / $pieces;
                $snapped = $beat ? round($time / $beat) * $beat : $time;
                $result[] = round($snapped > $start && $snapped < $end ? $snapped : $time, 2);
            }
        }

        return $result;
    }
}
