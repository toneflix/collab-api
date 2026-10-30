<?php

namespace App\Services\Ai\Video;

/**
 * The part of a song a video trial renders: the song audio cut to the window,
 * and the still and vocal the models read, uploaded for them.
 */
class TrialWindow
{
    /**
     * @param  list<string>  $files  Local temporary files to delete when done
     */
    public function __construct(
        public readonly string $song,
        public readonly float $from,
        public readonly float $seconds,
        public readonly string $image,
        public readonly ?string $vocals,
        public readonly array $files,
    ) {}

    public function forget(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }
}
