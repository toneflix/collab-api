<?php

namespace App\Services\Ai\Music;

/**
 * A song a music model made, as a local temporary file.
 */
class Song
{
    /**
     * @param  array<string, mixed>  $meta  What to keep on the stem under `ai`, like the model
     */
    public function __construct(
        public string $path,
        public string $extension = 'mp3',
        public ?string $title = null,
        public ?float $duration = null,
        public array $meta = [],
    ) {}

    public function forget(): void
    {
        @unlink($this->path);
    }
}
