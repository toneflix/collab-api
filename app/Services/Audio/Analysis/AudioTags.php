<?php

namespace App\Services\Audio\Analysis;

use App\Enums\MusicalKey;

/**
 * The tempo and key detected in a recording, with how sure each guess is
 * from 0 to 1.
 */
final readonly class AudioTags
{
    public function __construct(
        public ?float $bpm,
        public ?MusicalKey $key,
        public float $bpmConfidence = 0,
        public float $keyConfidence = 0,
    ) {}
}
