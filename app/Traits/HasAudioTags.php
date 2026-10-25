<?php

namespace App\Traits;

use App\Enums\MusicalKey;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tempo and key tags on a model with audio. Tags are "detected" from the
 * audio or "manual" when the creator set them.
 *
 * @property float|null $bpm
 * @property MusicalKey|null $musical_key
 * @property string|null $tags_source
 */
trait HasAudioTags
{
    public const TAGS_DETECTED = 'detected';

    public const TAGS_MANUAL = 'manual';

    public function initializeHasAudioTags(): void
    {
        $this->mergeCasts([
            'bpm' => 'float',
            'musical_key' => MusicalKey::class,
            'tagged_at' => 'datetime',
        ]);
    }

    /**
     * The tags as the app shows them.
     *
     * @return array{bpm: float|null, key: string|null, keyShort: string|null, camelot: string|null, source: string|null}
     */
    public function audioTags(): array
    {
        return [
            'bpm' => $this->bpm,
            'key' => $this->musical_key?->value,
            'keyShort' => $this->musical_key?->short(),
            'camelot' => $this->musical_key?->camelot(),
            'source' => $this->tags_source,
        ];
    }

    public function scopeBpmBetween(Builder $query, ?float $min, ?float $max): void
    {
        $column = $this->qualifyColumn('bpm');

        $query
            ->when($min !== null, fn(Builder $q) => $q->where($column, '>=', $min))
            ->when($max !== null, fn(Builder $q) => $q->where($column, '<=', $max));
    }

    /**
     * Only audio in one of these keys, plus the keys that mix well with them
     * when $compatible is set.
     *
     * @param  list<MusicalKey>  $keys
     */
    public function scopeInKey(Builder $query, array $keys, bool $compatible = false): void
    {
        if ($compatible) {
            $keys = collect($keys)
                ->flatMap(fn(MusicalKey $key) => $key->compatible())
                ->all();
        }

        $query->whereIn($this->qualifyColumn('musical_key'), collect($keys)->map->value->unique()->values());
    }
}
