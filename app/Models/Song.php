<?php

namespace App\Models;

use App\Enums\MusicalKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A song written in Composer: lyrics with [Section] tags, a private
 * scratchpad and notes, an optional beat and the writer's editor settings.
 *
 * @property array<string, mixed>|null $settings
 */
class Song extends Model
{
    use SoftDeletes;

    /**
     * Editor settings a writer can change, with their defaults.
     */
    public const SETTINGS = [
        'auto_suggest' => false,
        'rhyme_colours' => true,
        'mood' => null,
        'language' => null,
        'explicit' => false,
    ];

    protected $attributes = [
        'revision' => 1,
    ];

    protected $fillable = [
        'title',
        'body',
        'scratchpad',
        'notes',
        'bpm',
        'key',
        'beat_sound_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'key' => MusicalKey::class,
            'bpm' => 'integer',
            'revision' => 'integer',
            'line_count' => 'integer',
            'word_count' => 'integer',
            'versioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Song $song) {
            $song->line_count = count($song->lyricLines());
            $song->word_count = preg_match_all("/[\\p{L}\\p{N}']+/u", implode(' ', $song->lyricLines()));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beat(): BelongsTo
    {
        return $this->belongsTo(Sound::class, 'beat_sound_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SongVersion::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)) . '%';

        return $query->where(fn(Builder $q) => $q
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->orWhereRaw('LOWER(body) LIKE ?', [$like]));
    }

    /**
     * The sung lines: blank lines and [Section] tags left out.
     *
     * @return list<string>
     */
    public function lyricLines(): array
    {
        return collect(preg_split('/\R/u', (string) $this->body))
            ->map(fn(string $line) => trim($line))
            ->reject(fn(string $line) => $line === '' || preg_match('/^\[[^\]]*\]$/u', $line))
            ->values()
            ->all();
    }

    /**
     * The writer's settings with defaults filled in.
     *
     * @return array<string, mixed>
     */
    public function editorSettings(): array
    {
        return array_merge(self::SETTINGS, array_intersect_key($this->settings ?? [], self::SETTINGS));
    }
}
