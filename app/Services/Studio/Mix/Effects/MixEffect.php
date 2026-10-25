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
        'tf-highpass' => HighPass::class,
        'tf-width' => StereoWidth::class,
        'tf-saturation' => Saturation::class,
        'tf-crusher' => Bitcrusher::class,
        'tf-tremolo' => Tremolo::class,
        'tf-autopan' => AutoPan::class,
        'tf-limiter' => Limiter::class,
        'tf-chorus' => Chorus::class,
        'tf-flanger' => Flanger::class,
        'tf-phaser' => Phaser::class,
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
     * Whether the effect needs a stereo input. A mono stem is made stereo
     * before it, as Web Audio does.
     */
    public function stereo(): bool
    {
        return false;
    }

    /**
     * Run the effect on a pad and return the pad it comes out of.
     */
    abstract public function apply(MixGraph $graph, string $in): string;

    protected function param(string $key, float $min, float $max): float
    {
        return max($min, min($max, $this->params[$key] ?? static::DEFAULTS[$key] ?? $min));
    }

    /**
     * An aeval filter that works out each output sample from the input
     * sample `$x` of the same channel.
     *
     * @param  callable(string): string  $expression
     */
    protected function perSample(callable $expression): string
    {
        $channels = array_map(fn(int $c) => $expression("val({$c})"), range(0, $this->channels - 1));

        return "aeval=exprs='" . implode('|', $channels) . "':c=same";
    }

    protected static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
