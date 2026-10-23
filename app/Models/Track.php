<?php

namespace App\Models;

use App\Jobs\ProcessAudio;
use App\Traits\HasLikedScope;
use App\Traits\Metricable;
use App\Traits\ModelCanExtend;
use BeyondCode\Comments\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Toneflix\LaravelFileable\Traits\Fileable;
use Toneflix\SocialInteractions\Traits\HasSocialInteractions;

class Track extends Model
{
    use Fileable;
    use HasComments;
    use HasFactory;
    use HasLikedScope;
    use HasSocialInteractions;
    use Metricable;
    use ModelCanExtend;

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
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy' => 'public',
        'meta' => '{
            "processed": false
        }'
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
            'explicit' => 'boolean',
            'meta' => \Illuminate\Database\Eloquent\Casts\AsCollection::class,
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
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->where('slug', $value);

        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->firstOrFail();
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
            $model->artists()->detach();
        });

        static::saved(function (self $model) {
            ProcessAudio::dispatchIf(
                config('settings.encoding.enabled', false) && ! ($model->meta['processed'] ?? false),
                $model
            );
        });

        static::addGlobalScopes([
            fn (Builder $query) => $query->withCount([
                'visits as streams' => fn ($q) => $q->where('data->duration', '>', 0),
            ]),
        ]);
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
        return $this->artists()->wherePivot('type', 'primary');
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
        return $this->belongsTo(Genre::class)->withDefault(fn () => Genre::first() ?? [
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
        return $this->belongsTo(Release::class)->withDefault(fn () => [
            'title' => 'Unknown',
        ]);
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, Release::class)->withDefault(fn () => User::firstWhere('curator', true));
    }

    public function scopeForFeaturedArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artists', function (Builder $q) use ($id) {
            $q->whereNot('track_artist.type', 'primary');
            $q->where(function (Builder $qx) use ($id) {
                $qx->where('artists.username', $id)->when(is_numeric($id), fn ($q) => $q->orWhere('artists.id', $id));
            });
        });
    }

    public function scopeForArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artists', function (Builder $q) use ($id) {
            $q->where('artists.username', $id)->when(is_numeric($id), fn ($q) => $q->orWhere('artists.id', $id));
        });
    }

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('genre', function (Builder $q) use ($genre) {
            $q->where('slug', $genre)->when(is_numeric($genre), fn ($q) => $q->orWhere('id', $genre));
        });
    }

    public function scopeRecentlyPlayed(Builder $query, User|string $user, int $days = 1): void
    {
        $query->userThatPlayed($user)->popularPlayedLastDays($days);
    }

    public function scopeUserCollabs(Builder $query, User|string $user, ?string $period = 'years_2'): void
    {
        $query->whereHas('release', function (Builder $q) use ($period, $user) {
            $q->userCollabs($user, $period);
        });
    }
}