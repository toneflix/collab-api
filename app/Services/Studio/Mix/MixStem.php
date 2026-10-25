<?php

namespace App\Services\Studio\Mix;

use App\Services\Studio\Mix\Effects\MixEffect;

/**
 * A stem's place in the mix, read from the `meta` the studio saves: its
 * volume, pan, mute and solo, clips and effects, and its printed audio when
 * a plugin FFmpeg can't run is on.
 */
class MixStem
{
    /**
     * @param  list<array{start: float, offset: float, duration: ?float, gain: float, fadeIn: float, fadeOut: float}>  $clips
     * @param  list<array<string, mixed>>  $effects
     * @param  array{file: string, start: float}|null  $print
     */
    public function __construct(
        public readonly float $volume,
        public readonly float $pan,
        public readonly bool $muted,
        public readonly bool $soloed,
        public readonly array $clips,
        public readonly array $effects,
        public readonly ?array $print = null,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fromMeta(array $meta): self
    {
        $effects = self::effects($meta['effects'] ?? null);

        return new self(
            volume: isset($meta['gain']) ? max(0, min(1, self::num($meta['gain'], 1))) : 1.0,
            pan: max(-1, min(1, self::num($meta['stereoPan'] ?? 0))),
            muted: self::bool($meta['muted'] ?? false),
            soloed: self::bool($meta['soloed'] ?? false),
            clips: self::clips($meta),
            effects: $effects,
            print: self::print($meta['print'] ?? null, $effects),
        );
    }

    /**
     * The same stem played from its printed audio: one clip where the print
     * starts, and no effects, as they're in the audio already.
     */
    public function printed(): self
    {
        return new self(
            volume: $this->volume,
            pan: $this->pan,
            muted: $this->muted,
            soloed: $this->soloed,
            clips: [[
                'start' => $this->print['start'] ?? 0.0,
                'offset' => 0.0,
                'duration' => null,
                'gain' => 1.0,
                'fadeIn' => 0.0,
                'fadeOut' => 0.0,
            ]],
            effects: [],
        );
    }

    /**
     * The stem's effects that FFmpeg can render, in order.
     *
     * @return list<MixEffect>
     */
    public function mixEffects(int $channels): array
    {
        return array_values(array_filter(array_map(
            fn(array $effect) => MixEffect::from($effect, $channels),
            $this->effects,
        )));
    }

    /**
     * The clips of a stem. Stems saved by the old editor have no `clips`, so
     * their one clip is rebuilt from `start`, `cuein`, `cueout` and the fades.
     *
     * @param  array<string, mixed>  $meta
     * @return list<array{start: float, offset: float, duration: ?float, gain: float, fadeIn: float, fadeOut: float}>
     */
    protected static function clips(array $meta): array
    {
        if (! empty($meta['clips']) && is_array($meta['clips'])) {
            return array_values(array_map(fn($clip) => [
                'start' => max(0, self::num($clip['start'] ?? 0)),
                'offset' => max(0, self::num($clip['offset'] ?? 0)),
                'duration' => self::num($clip['duration'] ?? 0) > 0 ? self::num($clip['duration']) : null,
                'gain' => self::num($clip['gain'] ?? 1, 1),
                'fadeIn' => max(0, self::num($clip['fadeIn'] ?? 0)),
                'fadeOut' => max(0, self::num($clip['fadeOut'] ?? 0)),
            ], array_filter($meta['clips'], 'is_array')));
        }

        $offset = max(0, self::num($meta['cuein'] ?? 0));
        $cueout = self::num($meta['cueout'] ?? 0);

        return [[
            'start' => max(0, self::num($meta['start'] ?? 0)),
            'offset' => $offset,
            'duration' => $cueout > $offset ? $cueout - $offset : null,
            'gain' => 1.0,
            'fadeIn' => max(0, self::num($meta['fadeIn']['duration'] ?? 0)),
            'fadeOut' => max(0, self::num($meta['fadeOut']['duration'] ?? 0)),
        ]];
    }

    /**
     * The effect chain, saved as JSON text.
     *
     * @return list<array<string, mixed>>
     */
    protected static function effects(mixed $value): array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;

        return is_array($list) ? array_values(array_filter(array_slice($list, 0, 8), 'is_array')) : [];
    }

    /**
     * The printed audio, used only while a plugin is on in the chain.
     *
     * @param  list<array<string, mixed>>  $effects
     * @return array{file: string, start: float}|null
     */
    protected static function print(mixed $print, array $effects): ?array
    {
        $plugin = collect($effects)->contains(
            fn(array $e) => ($e['type'] ?? null) === 'wam' && ! self::bool($e['bypassed'] ?? false),
        );

        if (! $plugin || ! is_array($print) || ! is_string($print['file'] ?? null) || $print['file'] === '') {
            return null;
        }

        return ['file' => $print['file'], 'start' => max(0, self::num($print['start'] ?? 0))];
    }

    protected static function num(mixed $value, float $fallback = 0): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }

    protected static function bool(mixed $value): bool
    {
        return in_array($value, [true, 'true', 1, '1'], true);
    }
}
