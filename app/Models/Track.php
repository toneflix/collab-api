<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use ToneflixCode\LaravelFileable\Traits\Fileable;
use App\Traits\Metricable;

class Track extends Model
{
    use Fileable;
    use HasFactory;
    use ModelCanExtend;
    use Metricable;

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
    ];

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        if ($key === 'artist') {
            return $this->artist()->first();
        }

        return $this->getAttribute($key);
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
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });

        static::deleting(function (self $model) {
            $model->artists()->detach();
        });
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
        // ->withDefault(fn () => $this->artists()->first() ?? [
        //     'id' => 'unknown',
        //     'name' => 'Unknown',
        //     'fullname' => 'Unknown',
        //     'username' => 'unknown',
        //     'imageUrl' => $this->files['image'] ?? $this->projectTrack->project->files['image'] ?? '',
        // ]);
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
                $qx->where('artists.id', $id)->orWhere('artists.username', $id);
            });
        });
    }

    public function scopeForArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artists', function (Builder $q) use ($id) {
            $q->where('artists.id', $id)->orWhere('artists.username', $id);
        });
    }

    public function scopeRecentlyPlayed(Builder $query, User|string $user, int $days = 1): void
    {
        $query->userThatPlayed($user)->popularPlayedLastDays($days);
    }
}
