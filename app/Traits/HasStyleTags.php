<?php

namespace App\Traits;

use App\Enums\Mood;
use App\Models\Genre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mood tags and a suggested genre on a model with audio. Moods are
 * "detected" from the audio and lyrics, or "manual" when the creator set
 * them. The suggested genre is what the audio sounds like; the creator's
 * own genre is never changed.
 *
 * @property list<string>|null $moods
 * @property string|null $moods_source
 * @property int|null $suggested_genre_id
 * @property array<string, mixed>|null $style_scores
 */
trait HasStyleTags
{
    public function initializeHasStyleTags(): void
    {
        $this->mergeCasts([
            'moods' => 'array',
            'style_scores' => 'array',
            'styled_at' => 'datetime',
        ]);
    }

    public function suggestedGenre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'suggested_genre_id');
    }

    /**
     * The moods and suggested genre as the app shows them.
     *
     * @return array{moods: list<string>, source: string|null, suggestedGenreId: int|null}
     */
    public function styleTags(): array
    {
        return [
            'moods' => array_values($this->moods ?? []),
            'source' => $this->moods_source,
            'suggestedGenreId' => $this->suggested_genre_id,
        ];
    }

    /**
     * Only audio tagged with any of these moods.
     *
     * @param  list<Mood>  $moods
     */
    public function scopeWithMood(Builder $query, array $moods): void
    {
        $column = $this->qualifyColumn('moods');

        $query->where(function (Builder $q) use ($moods, $column) {
            foreach ($moods as $mood) {
                $q->orWhereJsonContains($column, $mood->value);
            }
        });
    }
}
