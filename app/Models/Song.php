<?php

namespace App\Models;

use App\Contracts\CanBeTakenDown;
use App\Enums\MusicalKey;
use App\Traits\TakesDown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Toneflix\LaravelFileable\Traits\Fileable;

/**
 * A song written in Composer: lyrics with [Section] tags, a private
 * scratchpad and notes, an optional beat and the writer's editor settings.
 * A published song's lyrics and artwork are public, and its writer chooses
 * whether others may fork it or start a project with it.
 *
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $published_at
 */
class Song extends Model implements CanBeTakenDown
{
    use Fileable;
    use SoftDeletes;
    use TakesDown;

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
            'published_at' => 'datetime',
            'allow_forks' => 'boolean',
            'allow_projects' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Song $song) {
            $song->line_count = count($song->lyricLines());
            $song->word_count = preg_match_all("/[\\p{L}\\p{N}']+/u", implode(' ', $song->lyricLines()));
        });
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'artwork',
        ], 'default', true, false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beat(): BelongsTo
    {
        return $this->belongsTo(Sound::class, 'beat_sound_id');
    }

    /**
     * The project track made from this song, whose lyric sheet it updates.
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(ProjectTrack::class, 'project_track_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SongVersion::class);
    }

    /**
     * The song this one was forked from.
     */
    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forked_from_id');
    }

    public function forks(): HasMany
    {
        return $this->hasMany(self::class, 'forked_from_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('published_at'));
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function whereOwnedBy(Builder $query, int $userId): void
    {
        $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function moderationOwnerId(): ?int
    {
        return (int) $this->user_id;
    }

    public function moderationSummary(): array
    {
        return [
            'title' => (string) $this->title,
            'subtitle' => 'Composition',
            'image' => $this->images['image'] ?? null,
            'link' => $this->slug ? '/compositions/' . $this->slug : null,
        ];
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
