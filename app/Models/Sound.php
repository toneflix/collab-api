<?php

namespace App\Models;

use App\Contracts\CanBeTakenDown;
use App\Traits\TakesDown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Toneflix\LaravelFileable\Media;

/**
 * A sound people can add to a track. With no user it belongs to the shared
 * library; otherwise it is that user's own sound.
 *
 * @property int|null $user_id
 * @property string $source
 * @property string|null $source_id
 * @property string $file
 * @property int $size
 */
class Sound extends Model implements CanBeTakenDown
{
    use TakesDown;

    public const COLLECTION = 'sounds';

    protected $fillable = [
        'user_id',
        'source',
        'source_id',
        'name',
        'category',
        'tags',
        'bpm',
        'key',
        'duration',
        'author',
        'license',
        'source_url',
        'quality',
        'file',
        'mime',
        'size',
        'waveform',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'waveform' => 'array',
            'bpm' => 'float',
            'duration' => 'float',
            'size' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $sound) {
            // Another sound can share the file, e.g. a library copy.
            if (! self::query()->withoutGlobalScopes()->where('file', $sound->file)->exists()) {
                (new Media)->delete(self::COLLECTION, $sound->file);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function whereOwnedBy(Builder $query, int $userId): void
    {
        $query->where('sounds.user_id', $userId);
    }

    public function moderationOwnerId(): ?int
    {
        return $this->user_id ? (int) $this->user_id : null;
    }

    public function moderationSummary(): array
    {
        return [
            'title' => (string) $this->name,
            'subtitle' => $this->user_id ? 'My sounds' : 'Sound library',
            'image' => null,
            'link' => null,
        ];
    }

    /**
     * Sounds in the shared library.
     */
    public function scopeLibrary(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Match a search against the name, category, author and tags.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(category) LIKE ?', [$like])
            ->orWhereRaw('LOWER(author) LIKE ?', [$like])
            ->orWhereRaw('LOWER(CAST(tags AS TEXT)) LIKE ?', [$like]));
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaInfo(): array
    {
        return (new Media)->mediaInfo(self::COLLECTION, $this->file);
    }

    /**
     * Where the file is on its disk, for copying it.
     */
    public function path(): string
    {
        return $this->mediaInfo()['path'];
    }
}
