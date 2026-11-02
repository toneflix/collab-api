<?php

namespace App\Models;

use App\Jobs\TagAudio;
use App\Jobs\TagStyle;
use App\Services\Artists\FeaturedArtists;
use App\Traits\HasAudioTags;
use App\Traits\HasStyleTags;
use App\Traits\ModelCanExtend;
use BeyondCode\Comments\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Toneflix\LaravelFileable\Traits\Fileable;

class ProjectTrack extends Model
{
    use Fileable;
    use HasAudioTags;
    use HasComments;
    use HasFactory;
    use HasStyleTags;
    use ModelCanExtend;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /** Meters the studio's beat grid supports */
    public const TIME_SIGNATURES = ['2/4', '3/4', '4/4', '5/4', '6/8', '7/8', '12/8'];

    protected $fillable = [
        'meta',
        'title',
        'privacy',
        'genre_id',
        'artist_id',
        'description',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy' => 'private',
        'meta' => '{}',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => AsCollection::class,
        ];
    }

    /**
     * The length of the mixed track in seconds: where the last stem ends.
     */
    protected function duration(): Attribute
    {
        return Attribute::make(
            get: fn() => (int) round($this->stems->map(function (ProjectTrackStem $stem) {
                $meta = json_decode($stem->getRawOriginal('meta') ?? '', true) ?: [];
                $start = (float) ($meta['start'] ?? 0);
                $end = (float) ($meta['end'] ?? 0);

                return max($end, $start + (float) ($meta['duration'] ?? 0));
            })->max() ?? 0),
        );
    }

    public function registerFileable()
    {
        $this->fileableLoader('preview', 'music_tracks', false, false);
    }

    public static function registerEvents(): void
    {
        static::creating(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });

        static::deleting(function (self $model) {
            $model
                ->stems()
                ->withTrashed()
                ->forceDelete();
            $model
                ->assets()
                ->delete();
            $model
                ->lyric()
                ->delete();
            // Without the stream counts Track adds, which a subquery can't carry.
            $released = $model
                ->releasedTracks()
                ->withoutGlobalScopes()
                ->select('id');

            Lyric::query()
                ->where('lyricable_type', (new Track)->getMorphClass())
                ->whereIn('lyricable_id', $released)
                ->delete();
            $model
                ->releasedTracks()
                ->delete();
        });

        static::saved(function (self $model) {
            if ($model->preview && ($model->wasRecentlyCreated || $model->wasChanged('preview'))) {
                TagAudio::dispatch($model)
                    ->afterCommit();
                TagStyle::dispatch($model)
                    ->afterCommit();
            }
        });

        // Published versions keep their details; only draft releases follow the studio.
        static::updated(function (self $model) {
            $model->releasedTracks()
                ->whereHas('release', fn(Builder $q) => $q->where('draft', true))
                ->chunkById(100, function ($tracks) use ($model) {
                    foreach ($tracks as $track) {
                        $track->title = $model->title;
                        $track->genre_id = $model->genre_id;
                        $track->saveQuietly();

                        if ($model->wasChanged('artist_id')) {
                            $track->credit($model->artist_id);
                        }
                    }
                });
        });
    }

    /**
     * The artists the track features, in the order they are credited.
     */
    public function featured(): BelongsToMany
    {
        return $this
            ->belongsToMany(Artist::class, 'project_track_artist')
            ->withTimestamps()
            ->orderByPivot('id');
    }

    /**
     * Set the artists the track features, in order. Tracks of its draft
     * releases follow; published ones keep their credits.
     *
     * @param  list<int|string>  $artistIds
     */
    public function feature(array $artistIds): void
    {
        $artistIds = FeaturedArtists::without($artistIds, $this->artist_id);

        DB::transaction(function () use ($artistIds) {
            $this
                ->featured()
                ->detach();

            foreach ($artistIds as $artistId) {
                $this
                    ->featured()
                    ->attach($artistId);
            }
        });

        $this->unsetRelation('featured');

        $this->releasedTracks()
            ->whereHas('release', fn(Builder $q) => $q->where('draft', true))
            ->chunkById(100, function ($tracks) use ($artistIds) {
                foreach ($tracks as $track) {
                    $track->credit($this->artist_id, $artistIds);
                }
            });
    }

    /**
     * Ids of the artists the track features, in order.
     *
     * @return list<int>
     */
    public function featuredIds(): array
    {
        return $this
            ->featured()
            ->pluck('artists.id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /**
     * Get the artist for the Track
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Get the assets for the Track
     */
    public function assets(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
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
     * Get the project that owns the Track
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The released tracks made from this project track, one per release version.
     */
    public function releasedTracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function lyric(): MorphOne
    {
        return $this->morphOne(Lyric::class, 'lyricable');
    }

    /**
     * Get the stems for the Track
     */
    /**
     * The Composer song this track was made from, if any.
     */
    public function song(): HasOne
    {
        return $this->hasOne(Song::class, 'project_track_id');
    }

    public function stems(): HasMany
    {
        return $this->hasMany(ProjectTrackStem::class, 'track_id');
    }

    /**
     * Get the saved versions of the Track, newest last
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ProjectTrackVersion::class, 'track_id');
    }

    /**
     * Every track for the project's owner and collaborators. Everyone else
     * sees the public tracks of projects they can see, or all of a project's
     * tracks when it makes every track public.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where(fn(Builder $q) => $q
                ->whereHas('project', fn(Builder $p) => $p->visibleTo(null))
                ->where(fn(Builder $q) => $q
                    ->where('project_tracks.privacy', 'public')
                    ->orWhereHas('project', fn(Builder $p) => $p->where('projects.tracks_public', true))));

            if ($user) {
                $query->orWhereHas('project', fn(Builder $q) => $q->where(fn(Builder $q) => $q
                    ->where('projects.user_id', $user->id)
                    ->orWhereHas('collaborators', fn(Builder $c) => $c->where('user_id', $user->id)->where('accepted', true))));
            }
        });
    }

    public function scopeReady(Builder $query): void
    {
        $query->whereHas('stems', fn(Builder $q) => $q->whereNotNull('src'));
    }

    /**
     * Get the user that created the Project
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
