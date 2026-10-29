<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A listener's own list of tracks, private unless they make it public.
 * Its link carries a random suffix, so private playlists can't be guessed.
 */
class Playlist extends Model
{
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'privacy',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'privacy' => self::PRIVATE,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $playlist) {
            $base = Str::slug(Str::limit($playlist->title, 60, '')) ?: 'playlist';

            $playlist->slug ??= $base . '-' . Str::lower(Str::random(8));
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this
            ->where($field ?? 'slug', $value)
            ->when(is_numeric($value) && ! $field, fn(Builder $q) => $q->orWhere('id', $value))
            ->firstOrFail();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coverTrack(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'cover_track_id');
    }

    /**
     * The playlist's tracks in their saved order.
     */
    public function tracks(): BelongsToMany
    {
        return $this
            ->belongsToMany(Track::class)
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function isPublic(): bool
    {
        return $this->privacy === self::PUBLIC;
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user && (int) $this->user_id === (int) $user->id;
    }

    /**
     * Playlists the user owns.
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('user_id'), $user->id);
    }
}
