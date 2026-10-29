<?php

namespace App\Services\Catalog;

use App\Enums\ArtworkKind;
use App\Jobs\DrawGenreCover;
use App\Models\Genre;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Tasks\GenerateArtwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Draws genre covers with the AI artwork model and saves them through Genres.
 * A genre whose cover file is missing counts as having no cover.
 */
class GenreCovers
{
    /** Genres one request may queue covers for */
    public const BATCH = 20;

    public function __construct(
        protected Genres $genres,
        protected ArtworkImages $images,
    ) {}

    /**
     * Find a genre by id, slug or title.
     */
    public function find(string $genre): ?Genre
    {
        $genre = trim($genre);

        return Genre::query()
            ->when(
                ctype_digit($genre),
                fn(Builder $q) => $q->whereKey((int) $genre),
                fn(Builder $q) => $q
                    ->where('slug', $genre)
                    ->orWhereRaw('lower(title) = ?', [mb_strtolower($genre)]),
            )
            ->first();
    }

    /**
     * Up to $limit genres without a cover, checked in batches.
     *
     * @return Collection<int, Genre>
     */
    public function missing(int $limit = self::BATCH): Collection
    {
        return Genre::query()
            ->lazyById(50)
            ->filter(fn(Genre $genre) => ! $genre->coverUrl())
            ->take($limit)
            ->collect()
            ->values();
    }

    /**
     * Queue drawing a cover for each genre. Without $replace, a genre that
     * has a cover by the time its turn comes keeps it.
     *
     * @param  iterable<Genre>  $genres
     */
    public function queue(iterable $genres, ?string $prompt = null, bool $replace = false): void
    {
        $this->ensureAvailable();

        foreach ($genres as $genre) {
            DrawGenreCover::dispatch($genre, $prompt, $replace);
        }
    }

    /**
     * Draw a cover for the genre and save it.
     */
    public function draw(Genre $genre, ?string $prompt = null, bool $replace = false): void
    {
        if (! $replace && $genre->coverUrl()) {
            return;
        }

        $kind = ArtworkKind::GENRE;
        $words = $kind->prompt($this->prompt($genre, $prompt), ['genre' => $genre->title]);
        $paths = GenerateArtwork::model()
            ->generate($words, $kind->ratio(), 1);

        try {
            if (! $paths) {
                throw new RuntimeException('The model returned no image.');
            }

            $image = $this->images->cropped($paths[0], $kind->ratio());

            try {
                $this->genres->cover($genre, $image);
            } finally {
                @unlink($image->getPathname());
            }
        } finally {
            array_map(fn(string $path) => @unlink($path), $paths);
        }
    }

    public function ensureAvailable(): void
    {
        if (! GenerateArtwork::model()->engine()->available()) {
            throw ValidationException::withMessages(['ai' => 'AI artwork isn\'t available right now.']);
        }
    }

    /**
     * The admin's words for the picture, or what the genre is about.
     */
    protected function prompt(Genre $genre, ?string $prompt): string
    {
        $prompt = trim((string) $prompt);

        if ($prompt !== '') {
            return $prompt;
        }

        return filled($genre->description)
            ? $genre->description
            : 'The mood, instruments and culture this music is known for';
    }
}
