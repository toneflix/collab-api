<?php

namespace App\Jobs;

use App\Models\Genre;
use App\Services\Catalog\GenreCovers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Draws and saves one genre's cover.
 */
class DrawGenreCover implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public Genre $genre,
        public ?string $prompt = null,
        public bool $replace = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->genre->id;
    }

    public function handle(GenreCovers $covers): void
    {
        $covers->draw($this->genre->refresh(), $this->prompt, $this->replace);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Genre cover failed', [
            'genre' => $this->genre->id,
            'error' => $e?->getMessage(),
        ]);
    }
}
