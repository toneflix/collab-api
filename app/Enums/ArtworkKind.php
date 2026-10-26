<?php

namespace App\Enums;

/**
 * What a piece of AI artwork is for, which sets its shape and what the
 * model is asked to draw.
 */
enum ArtworkKind: string
{
    case PROJECT = 'project';
    case RELEASE = 'release';
    case TRACK = 'track';
    case SONG = 'song';
    case ARTIST = 'artist';
    case ARTIST_COVER = 'artist_cover';

    /**
     * Width and height of the image, as a ratio.
     *
     * @return array{0: int, 1: int}
     */
    public function ratio(): array
    {
        return match ($this) {
            self::ARTIST_COVER => [2, 1],
            default => [1, 1],
        };
    }

    /**
     * The kind of picture the model is asked for.
     */
    public function subject(): string
    {
        return match ($this) {
            self::PROJECT => 'Cover art for a music project',
            self::RELEASE => 'Album cover art',
            self::TRACK => 'Single cover art',
            self::SONG => 'Cover art for a song',
            self::ARTIST => 'Profile picture for a music artist',
            self::ARTIST_COVER => 'Wide banner for a music artist\'s page, with a calm area in the middle',
        };
    }

    /**
     * The full prompt for the model, from the person's own words and what
     * the artwork is for.
     *
     * @param  array{title?: ?string, artist?: ?string, genre?: ?string}  $context
     */
    public function prompt(string $prompt, array $context = []): string
    {
        $about = collect([
            filled($context['title'] ?? null) ? "titled \"{$context['title']}\"" : null,
            filled($context['artist'] ?? null) ? "by {$context['artist']}" : null,
            filled($context['genre'] ?? null) ? "in the {$context['genre']} genre" : null,
        ])
            ->filter()
            ->join(', ');

        return collect([
            $this->subject() . ($about ? ", {$about}" : '') . '.',
            rtrim(trim($prompt), '.') . '.',
            'Striking composition, rich detail, professional quality.',
            'No text, letters, words, logos or watermarks.',
        ])
            ->join(' ');
    }
}
