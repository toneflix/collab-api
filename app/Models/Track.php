<?php

namespace App\Models;

use App\Contracts\CanBeTakenDown;
use App\Jobs\FingerprintTrack;
use App\Jobs\MeasureDuration;
use App\Jobs\ProcessAudio;
use App\Jobs\TagAudio;
use App\Jobs\TranscribeTrack;
use App\Traits\HasAudioTags;
use App\Traits\HasLikedScope;
use App\Traits\Metricable;
use App\Traits\ModelCanExtend;
use App\Traits\Purchasable;
use App\Traits\TakesDown;
use BeyondCode\Comments\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Toneflix\LaravelFileable\Traits\Fileable;
use Toneflix\SocialInteractions\Traits\HasSocialInteractions;

class Track extends Model implements CanBeTakenDown
{
    use Fileable;
    use HasAudioTags;
    use HasComments;
    use HasFactory;
    use HasLikedScope;
    use HasSocialInteractions;
    use Metricable;
    use ModelCanExtend;
    use Purchasable;
    use TakesDown;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'meta',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'slug',
        'title',
        'image',
        'audio',
        'privacy',
        'genre_id',
        'description',
        'project_track_id',
        'stems_price',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy' => 'public',
        'stems_price' => 0,
        'meta' => '{
            "processed": false
        }',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration' => 'float',
            'explicit' => 'boolean',
            'stems_price' => 'float',
            'meta' => AsCollection::class,
        ];
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        if ($key === 'artist') {
            return $this->artist()->first() ?? $this->artists()->first() ?? [
                'id' => 'unknown',
                'name' => 'Unknown',
                'fullname' => 'Unknown',
                'username' => 'unknown',
                'imageUrl' => $this->files['image'] ?? $this->projectTrack->project->files['image'] ?? '',
            ];
        }

        return $this->getAttribute($key);
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
     * The track's artwork, else its release's, else its studio project's.
     */
    public function artworkUrl(): ?string
    {
        if (! $this->image && $this->release?->image) {
            return $this->release->files['artwork'];
        }

        if (! $this->image && $this->projectTrack?->project) {
            return $this->projectTrack->project->files['image'];
        }

        return $this->files['image'];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'audio' => 'music_tracks',
            'image' => 'artwork',
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->slug = $model->generateUsername(
                $model->title,
                'slug',
                '-',
                $model->slug,
                // fn ($q) => $q->whereHas('artist', fn ($q) => $q->where('artist', ))
            );
        });

        static::deleting(function (self $model) {
            $model
                ->artists()
                ->detach();
            $model
                ->lyric()
                ->delete();
        });

        static::saved(function (self $model) {
            ProcessAudio::dispatchIf(
                config('settings.encoding.enabled', false) && ! ($model->meta['processed'] ?? false),
                $model
            );

            if ($model->audio && ($model->wasRecentlyCreated || $model->wasChanged('audio'))) {
                FingerprintTrack::dispatch($model);
            }

            if ($model->wasRecentlyCreated || $model->wasChanged(['audio', 'project_track_id'])) {
                $model->queueSoundChecks($model->wasRecentlyCreated ? (bool) $model->audio : $model->wasChanged('audio'));
            }
        });

        static::addGlobalScopes([
            fn(Builder $query) => $query->withCount([
                'visits as streams' => fn($q) => $q->where('data->duration', '>', 0),
            ]),
        ]);
    }

    /**
     * Give the track its length, lyrics and tempo/key, from its project track
     * or its own audio. Runs on save, and by hand for tracks saved quietly.
     */
    public function queueSoundChecks(bool $ownAudio): void
    {
        if (! $this->audio && ! $this->project_track_id) {
            return;
        }

        MeasureDuration::dispatch($this)
            ->afterCommit();
        TranscribeTrack::dispatch($this)
            ->afterCommit();
        TagAudio::dispatch($this, inherit: ! $ownAudio)
            ->afterCommit();
    }

    public function gusm(string $str, string $sep = '-', ?string $cur = null)
    {
        return $this->generateUsername($str, 'slug', $sep, $cur);
    }

    /**
     * Get all of the artists for the Track
     */
    public function artist()
    {
        return $this
            ->artists()
            ->wherePivot('type', 'primary');
    }

    /**
     * Get all of the artists for the Track
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'track_artist')
            ->using(TrackArtist::class)
            ->as('track')
            ->withTimestamps();
    }

    /**
     * Get the genre that owns the Release
     */
    public function genre(): BelongsTo
    {
        return $this
            ->belongsTo(Genre::class)
            ->withDefault(fn() => Genre::first() ?? [
                'id' => null,
                'title' => 'Unknown',
                'description' => 'Unknown',
            ]);
    }

    /**
     * Get the release that owns the Track
     */
    public function release(): BelongsTo
    {
        return $this
            ->belongsTo(Release::class)
            ->withDefault(fn() => [
                'title' => 'Unknown',
            ]);
    }

    public function lyric(): MorphOne
    {
        return $this->morphOne(Lyric::class, 'lyricable');
    }

    /**
     * Get the project track that owns the Track
     */
    public function projectTrack(): BelongsTo
    {
        return $this->belongsTo(ProjectTrack::class);
    }

    /**
     * Get the user that owns the Track
     *
     * @return BelongsTo
     */
    public function user(): HasOneThrough
    {
        return $this
            ->hasOneThrough(User::class, Release::class)
            ->withDefault(fn() => User::firstWhere('curator', true));
    }

    /**
     * Whether the user can stream this track: true unless its release is paid and locked.
     */
    public function isUnlockedFor(?User $user): bool
    {
        return ! $this->release || $this->release->isUnlockedFor($user);
    }

    /**
     * Whether this track has stems that could be downloaded.
     */
    public function hasStems(): bool
    {
        return (bool) $this->projectTrack?->stems()->whereNotNull('src')->exists();
    }

    /**
     * Stems are for sale when the creator set a price and the track has stems.
     */
    public function stemsForSale(): bool
    {
        return $this->stems_price > 0 && $this->hasStems();
    }

    /**
     * Whether the user can download this track's stems.
     */
    public function stemsUnlockedFor(?User $user): bool
    {
        return (bool) $this->release?->isOwnedBy($user) || $this->isPurchasedBy($user, 'stems');
    }

    /**
     * Only tracks the user may stream: free ones, their own, and ones they bought.
     */
    public function scopePlayableBy(Builder $query, ?User $user): void
    {
        $query->whereHas('release', function (Builder $q) use ($user) {
            $q->where(function (Builder $q) use ($user) {
                $q->where('price', '<=', 0);

                if ($user) {
                    $q->orWhere('releases.user_id', $user->id)
                        ->orWhereHas('transactions', fn(Builder $t) => $t
                            ->where('user_id', $user->id)
                            ->where('purpose', 'release')
                            ->where('status', 'complete'));
                }
            });
        });
    }

    public function whereOwnedBy(Builder $query, int $userId): void
    {
        $query->whereIn('tracks.release_id', fn($releases) => $releases
            ->select('id')
            ->from('releases')
            ->where('user_id', $userId));
    }

    public function moderationOwnerId(): ?int
    {
        $owner = Release::query()
            ->withoutGlobalScopes()
            ->whereKey($this->release_id)
            ->value('user_id');

        return $owner ? (int) $owner : null;
    }

    public function moderationSummary(): array
    {
        $artist = $this
            ->artists()
            ->withoutGlobalScopes()
            ->first();

        return [
            'title' => (string) $this->title,
            'subtitle' => $artist?->name,
            'image' => $this->files['image'] ?? null,
            'link' => $artist?->username ? "/artist/{$artist->username}/{$this->slug}" : null,
        ];
    }

    public function scopeForFeaturedArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artists', function (Builder $q) use ($id) {
            $q->whereNot('track_artist.type', 'primary');
            $q->where(function (Builder $qx) use ($id) {
                $qx
                    ->where('artists.username', $id)
                    ->when(is_numeric($id), fn($q) => $q->orWhere('artists.id', $id));
            });
        });
    }

    public function scopeForArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artists', function (Builder $q) use ($id) {
            $q
                ->where('artists.username', $id)
                ->when(is_numeric($id), fn($q) => $q->orWhere('artists.id', $id));
        });
    }

    /**
     * Tracks whose title, or lyrics, match the words searched for.
     */
    public function scopeSearch(Builder $query, string $terms): void
    {
        $terms = trim($terms);

        $query->where(fn(Builder $q) => $q
            ->whereLike('tracks.title', "%{$terms}%")
            ->orWhereHas('lyric', fn(Builder $lyric) => $lyric->whereFullText('text', $terms)));
    }

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('genre', function (Builder $q) use ($genre) {
            $q
                ->where('slug', $genre)
                ->when(is_numeric($genre), fn($q) => $q->orWhere('id', $genre));
        });
    }

    public function scopeRecentlyPlayed(Builder $query, User|string $user, int $days = 1): void
    {
        $query
            ->userThatPlayed($user)
            ->popularPlayedLastDays($days);
    }

    public function scopeUserCollabs(Builder $query, User|string $user, ?string $period = 'years_2'): void
    {
        $query->whereHas('release', function (Builder $q) use ($period, $user) {
            $q->userCollabs($user, $period);
        });
    }
}
