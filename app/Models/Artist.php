<?php

namespace App\Models;

use App\Helpers\Freq;
use App\Traits\ModelCanExtend;
use Coderflex\Laravisit\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Overtrue\LaravelFollow\Traits\Followable;
use ToneflixCode\LaravelFileable\Traits\Fileable;

class Artist extends Model
{
    use Fileable;
    use HasFactory;
    use ModelCanExtend;
    use Followable;

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
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->username ??= $model->generateUsername($model->name);
        });

        static::addGlobalScopes([
            fn (Builder $query) => $query->withCount(['followers'])
        ]);
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('id', $value)
            ->orWhere('username', $value)
            ->firstOrFail();
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
        return $this->belongsTo(User::class)->withDefault(fn () => User::firstWhere('curator', true));
    }

    public function recommendings(): MorphMany
    {
        return $this->morphMany(Recommendation::class, 'recommendable')->latest();
    }

    public function scopeTrending(Builder $query, ?string $period = 'years_2', string $direction = 'desc'): void
    {
        $days = Freq::period($period);

        $scope = fn (Builder $q) => $q
            ->where('data->duration', '>', 0)
            ->whereBetween('laravisits.created_at', [now()->subDays($days), now()]);

        $query->withCount(['tracksViews' => $scope])
            ->whereHas('tracksViews', $scope)
            ->orderBy('tracks_views_count', $direction);
    }
}