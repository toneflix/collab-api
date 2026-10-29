<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Genre;
use App\Models\User;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Catalog\GenreCovers;
use App\Services\Catalog\Genres;
use Illuminate\Validation\ValidationException;

/**
 * Draws AI covers for one genre, or for the genres that have none.
 */
class DrawGenreCovers extends AssistantTool
{
    public function __construct(protected GenreCovers $covers) {}

    public function name(): string
    {
        return 'draw_genre_covers';
    }

    public function description(): string
    {
        return 'Draw an AI cover image for one genre, or for every genre without a cover (' . GenreCovers::BATCH . ' per call). Covers are drawn in the background and show on the Genres page when ready.';
    }

    public function input(): array
    {
        return [
            'genre' => ['nullable|string|max:60', 'The genre\'s id, slug or name. Leave out with missing=true'],
            'missing' => ['nullable|boolean', 'true to draw covers for the genres that have none'],
            'prompt' => ['nullable|string|max:500', 'What the picture should show, without names or text. Defaults to the genre\'s description'],
        ];
    }

    public function permissions(): array
    {
        return [Genres::PERMISSION];
    }

    public function confirms(array $input): bool
    {
        return (bool) $this->genre($input)?->coverUrl();
    }

    public function summary(User $admin, array $input): string
    {
        $genre = $this->genre($input);

        return "Replace the cover of {$genre->title} with a new AI cover";
    }

    public function run(User $admin, array $input): array
    {
        $genre = $this->genre($input);

        if ($genre) {
            $this->covers->queue([$genre], $input['prompt'] ?? null, true);

            return ['drawing' => [$genre->title]];
        }

        $genres = $this->covers->missing();

        $this->covers->queue($genres, $input['prompt'] ?? null);

        return [
            'drawing' => $genres
                ->pluck('title')
                ->all(),
            'more' => $genres->count() === GenreCovers::BATCH,
        ];
    }

    /**
     * The genre the input names, or null when it asks for the missing covers.
     *
     * @param  array<string, mixed>  $input
     */
    protected function genre(array $input): ?Genre
    {
        $name = trim((string) ($input['genre'] ?? ''));

        if ($name === '') {
            if (empty($input['missing'])) {
                throw ValidationException::withMessages(['genre' => 'Name a genre, or ask for the genres without a cover.']);
            }

            return null;
        }

        $genre = $this->covers->find($name);

        if (! $genre) {
            throw ValidationException::withMessages(['genre' => "There is no genre “{$name}”."]);
        }

        return $genre;
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation when the genre already has a cover.';
    }
}
