<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A stem effect from the studio, rendered with FFmpeg filters that match
 * how the effect sounds in the editor.
 */
abstract class MixEffect
{
    /**
     * Effect types the studio saves, and the class that renders each.
     *
     * @var array<string, class-string<MixEffect>>
     */
    public const TYPES = [
        'tf-eq' => Equalizer::class,
        'tf-reverb' => Reverb::class,
        'native-delay' => Delay::class,
        'native-compressor' => Compressor::class,
        'native-filter' => LowPass::class,
    ];

    /**
     * The effect's settings before anyone changes them, as in the studio.
     *
     * @var array<string, float>
     */
    public const DEFAULTS = [];

    /**
     * @param  array<string, float>  $params
     */
    public function __construct(
        protected array $params,
        protected int $channels = 2,
    ) {}

    /**
     * Build an effect from what the studio saved, or null for one that's
     * unknown or switched off.
     *
     * @param  array<string, mixed>  $effect
     */
    public static function from(array $effect, int $channels = 2): ?self
    {
        $class = self::TYPES[$effect['type'] ?? ''] ?? null;
        $bypassed = filter_var($effect['bypassed'] ?? false, FILTER_VALIDATE_BOOL);

        if (! $class || $bypassed) {
            return null;
        }

        $params = array_map(fn($value) => (float) $value, array_filter(
            (array) ($effect['params'] ?? []),
            fn($value) => is_numeric($value),
        ));

        return new $class([...$class::DEFAULTS, ...$params], $channels);
    }

    /**
     * Run the effect on a pad and return the pad it comes out of.
     */
    abstract public function apply(MixGraph $graph, string $in): string;

    protected function param(string $key, float $min, float $max): float
    {
        return max($min, min($max, $this->params[$key] ?? static::DEFAULTS[$key] ?? $min));
    }

    protected static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
