<?php

namespace App\Models;

use App\Contracts\CanBeTakenDown;
use App\Helpers\Freq;
use App\Models\Scopes\HideDeactivatedAccounts;
use App\Models\Scopes\RestrictBlockedUserScope;
use App\Services\Ai\Video\MusicVideos;
use App\Traits\HasAiReviews;
use App\Traits\HasLikedScope;
use App\Traits\Metricable;
use App\Traits\ModelCanExtend;
use App\Traits\Purchasable;
use App\Traits\TakesDown;
use BeyondCode\Comments\Traits\HasComments;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Toneflix\LaravelFileable\Traits\Fileable;
use Toneflix\SocialInteractions\Traits\HasSocialInteractions;

#[ScopedBy([
    RestrictBlockedUserScope::class,
    HideDeactivatedAccounts::class,
])]
class Release extends Model implements CanBeTakenDown
{
    use Fileable;
    use HasAiReviews;
    use HasComments;
    use HasFactory;
    use HasLikedScope;
    use HasSocialInteractions;
    use Metricable;
    use ModelCanExtend;
    use Purchasable;
    use TakesDown;

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'slug',
        'isrc',
        'iswc',
        'title',
        'version',
        'image',
        'draft',
        'privacy',
        'explicit',
        'composer',
        'genre_id',
        'publisher',
        'artist_id',
        'project_id',
        'description',
        'record_label',
        'release_date',
        'contains_music',
        'price',
        'downloadable',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'draft' => false,
        'privacy' => 'public',
        'explicit' => false,
        'contains_music' => false,
        'price' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
            'retired_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'retire_on_approval' => 'array',
            'price' => 'float',
            'explicit' => 'boolean',
            'downloadable' => 'boolean',
            'release_date' => 'date:Y/m/d',
            'contains_music' => 'boolean',
        ];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'artwork' => 'artwork',
        ], 'default', true, false, 'image');
    }

    /**
     * Published releases that haven't been retired.
     */
    public function scopeLive(Builder $query): void
    {
        $query->where($this->qualifyColumn('draft'), false)
            ->whereNull($this->qualifyColumn('retired_at'))
            ->where(fn(Builder $q) => $q
                ->whereNull($this->qualifyColumn('review_status'))
                ->orWhere($this->qualifyColumn('review_status'), self::REVIEW_APPROVED));
    }

    /**
     * Releases waiting for an admin to approve them.
     */
    public function scopeInReview(Builder $query): void
    {
        $query
            ->where($this->qualifyColumn('draft'), false)
            ->where($this->qualifyColumn('review_status'), self::REVIEW_PENDING);
    }

    public function isInReview(): bool
    {
        return ! $this->draft && $this->review_status === self::REVIEW_PENDING;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public static function registerEvents()
    {
        static::saving(function (self $model) {
            $model->slug = $model->generateUsername($model->title, 'slug', '-', $model->slug);
        });

        static::deleting(function (self $model) {
            app(MusicVideos::class)->forgetForTracks($model->tracks());

            $model
                ->tracks()
                ->delete();
        });

        static::addGlobalScopes([
            fn(Builder $query) => $query
                ->withCount(['tracks'])
                ->withSum('tracks', 'duration'),
        ]);
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->where('slug', $value);

        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->firstOrFail();
    }

    /**
     * Get the artist that owns the Release
     */
    public function artist(): BelongsTo
    {
        return $this
            ->belongsTo(Artist::class)
            ->withDefault(fn() => Artist::make([
                'name' => 'Unknown',
                'about' => 'Unknown',
                'username' => 'unknown',
            ]));
    }

    /**
     * Topics the creator tagged the release with, like Heartbreak or Live music.
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class)
            ->withTimestamps();
    }

    /**
     * Get the genre that owns the Release
     */
    public function genre(): BelongsTo
    {
        return $this
            ->belongsTo(Genre::class)
            ->withDefault(fn() => Genre::first() ?? Genre::make([
                'slug' => 'unknown',
                'title' => 'Unknown',
                'description' => 'Unknown',
            ]));
    }

    /**
     * Get the project that owns the Release
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get all of the tracks for the Release
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * Plays of the release's tracks.
     */
    public function plays(): HasManyThrough
    {
        return $this->hasManyThrough(TrackPlay::class, Track::class);
    }

    /**
     * How many plays the release's tracks have.
     */
    public function streams(): Attribute
    {
        return Attribute::make(
            get: fn() => $this
                ->plays()
                ->count(),
        );
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
     * Whether the user owns this release.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user && (int) $this->user_id === (int) $user->id;
    }

    /**
     * Free releases are open to everyone; paid ones to their owner and buyers.
     */
    public function isUnlockedFor(?User $user): bool
    {
        return $this->price <= 0 || $this->isOwnedBy($user) || $this->isPurchasedBy($user, 'release');
    }

    public function whereOwnedBy(Builder $query, int $userId): void
    {
        $query->where('releases.user_id', $userId);
    }

    public function moderationOwnerId(): ?int
    {
        return $this->user_id ? (int) $this->user_id : null;
    }

    public function moderationSummary(): array
    {
        $artist = $this
            ->artist()
            ->withoutGlobalScopes()
            ->first();

        return [
            'title' => (string) $this->title,
            'subtitle' => $artist?->name,
            'image' => $this->files['artwork'] ?? null,
            'link' => $artist?->username ? "/artist/{$artist->username}/album/{$this->slug}" : null,
        ];
    }

    public function scopeForArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artist', function (Builder $q) use ($id) {
            $q
                ->where('username', $id)
                ->when(is_numeric($id), fn($q) => $q->orWhere('id', $id));
        });
    }

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('genre', function (Builder $q) use ($genre) {
            $q
                ->where('slug', $genre)
                ->when(is_numeric($genre), fn($q) => $q->orWhere('id', $genre));
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
    }

    public function scopeUserCollabs(Builder $query, User|string $user, ?string $period = 'years_2'): void
    {
        $days = Freq::period($period);

        $query->whereHas('project', function (Builder $q) use ($days, $user) {
            $q->collaber($user, $days);
        });
    }
}
