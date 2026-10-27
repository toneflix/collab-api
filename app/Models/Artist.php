<?php

namespace App\Models;

use App\Helpers\Freq;
use App\Models\Scopes\HideDeactivatedAccounts;
use App\Traits\ModelCanExtend;
use Coderflex\Laravisit\Models\Visit;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Overtrue\LaravelFollow\Traits\Followable;
use Toneflix\LaravelFileable\Traits\Fileable;

#[ScopedBy([HideDeactivatedAccounts::class])]
class Artist extends Model
{
    use Fileable;
    use Followable;
    use HasFactory;
    use ModelCanExtend;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'about',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'verified' => false,
        'reputation' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'reputation' => 'integer',
        ];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'avatar',
            'cover' => 'banner',
        ], 'default', true);
    }

    /**
     * Whether the user's account manages this artist.
     */
    public function isManagedBy(?User $user): bool
    {
        return $user && $this->user_id && (int) $this->user_id === (int) $user->id;
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->username ??= $model->generateUsername($model->name);
        });
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->where('username', $value);

        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->firstOrFail();
    }

    /**
     * Get all of the tracks for the Artist
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_artist')
            ->using(TrackArtist::class)
            ->as('artist')
            ->withTimestamps();
    }

    /**
     * Get all of the releases for the Artist
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function tracksViews(): HasManyThrough
    {
        return $this->hasManyThrough(
            Visit::class,
            TrackArtist::class,
            'artist_id',
            'visitable_id',
            'id',
            'track_id'
        )->where('laravisits.visitable_type', Track::class);
    }

    /**
     * Get the user that owns the Artist
     */
    public function user(): BelongsTo
    {
        return $this
            ->belongsTo(User::class)
            ->withDefault(fn() => User::firstWhere('curator', true));
    }

    /**
     * Requests from people who want to manage this artist.
     */
    public function claims(): HasMany
    {
        return $this->hasMany(ArtistClaim::class);
    }

    /**
     * An artist no account manages yet can be claimed.
     */
    public function isClaimable(): bool
    {
        return ! $this->user_id;
    }

    public function recommendings(): MorphMany
    {
        return $this
            ->morphMany(Recommendation::class, 'recommendable')
            ->latest();
    }

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('releases', function (Builder $q) use ($genre) {
            $q->whereHas('genre', function (Builder $q) use ($genre) {
                $q
                    ->where('slug', $genre)
                    ->when(is_numeric($genre), fn($q) => $q->orWhere('id', $genre));
            });
            $q->orWhereHas('tracks', function (Builder $qx) use ($genre) {
                $qx->whereHas('genre', function (Builder $q) use ($genre) {
                    $q
                        ->where('slug', $genre)
                        ->when(is_numeric($genre), fn($q) => $q->orWhere('id', $genre));
                });
            });
        });
    }

    public function scopeRecentlyPlayed(Builder $query, User|string $user, ?string $period = 'years_2'): void
    {
        $days = Freq::period($period);

        $query->whereHas('tracks', function (Builder $q) use ($days, $user) {
            $q
                ->userThatPlayed($user)
                ->popularPlayedLastDays($days);
        });
        $query->orWhereHas('releases', function (Builder $q) use ($days, $user) {
            $q->whereHas('tracks', function (Builder $qx) use ($days, $user) {
                $qx
                    ->userThatPlayed($user)
                    ->popularPlayedLastDays($days);
            });
        });
    }

    public function scopeTrending(Builder $query, ?string $period = 'years_2', string $direction = 'desc'): void
    {
        $days = Freq::period($period);

        $scope = fn(Builder $q) => $q
            ->where('data->duration', '>', 0)
            ->whereBetween('laravisits.created_at', [now()->subDays($days), now()]);

        $query->withCount(['tracksViews' => $scope])
            ->whereHas('tracksViews', $scope)
            ->orderBy('tracks_views_count', $direction);
    }
}
