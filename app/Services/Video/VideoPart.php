<?php

namespace App\Services\Video;

/**
 * One piece of an edit list: a stretch of a local clip, or a still moved
 * with a slow zoom or pan, played for a length of time.
 */
final readonly class VideoPart
{
    public const ZOOM_IN = 'zoom_in';

    public const ZOOM_OUT = 'zoom_out';

    public const PAN = 'pan';

    /**
     * @param  string  $path  Local clip or image
     * @param  float  $length  Seconds the part plays
     * @param  float  $offset  Seconds into the clip the part starts
     * @param  ?string  $motion  How a still moves; null for a clip
     */
    public function __construct(
        public string $path,
        public float $length,
        public float $offset = 0.0,
        public ?string $motion = null,
    ) {}

    public static function clip(string $path, float $length, float $offset = 0.0): self
    {
        return new self($path, $length, $offset);
    }

    public static function still(string $path, float $length, string $motion): self
    {
        return new self($path, $length, 0.0, $motion);
    }

    public function isStill(): bool
    {
        return $this->motion !== null;
    }
}
