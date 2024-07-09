<?php

namespace App\Models;

use App\Traits\Metricable;
use App\Traits\ModelCanExtend;
use Coderflex\Laravisit\Concerns\CanVisit;
use Coderflex\Laravisit\Concerns\HasVisits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ToneflixCode\LaravelFileable\Traits\Fileable;

class Release extends Model implements CanVisit
{
    use Fileable;
    use HasFactory;
    use HasVisits;
    use Metricable;
    use ModelCanExtend;

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
            'explicit' => 'boolean',
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

    public static function registerEvents()
    {
        static::saving(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });

        static::deleting(function (self $model) {
            $model->tracks()->delete();
        });

        static::addGlobalScopes([
            fn (Builder $query) => $query->withCount(['tracks']),
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
            ->orWhere('slug', $value)
            ->firstOrFail();
    }

    /**
     * Get the artist that owns the Release
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class)->withDefault(fn () => Artist::make([
            'name' => 'Unknown',
            'about' => 'Unknown',
            'username' => 'unknown',
        ]));
    }

    /**
     * Get the genre that owns the Release
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class)->withDefault(fn () => Genre::first() ?? Genre::make([
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
     * Get the user that owns the Artist
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(fn () => User::firstWhere('curator', true));
    }

    public function scopeForArtist(Builder $query, Artist|string $artist): void
    {
        $id = is_string($artist) ? $artist : $artist->id;
        $query->whereHas('artist', function (Builder $q) use ($id) {
            $q->where('id', $id)->orWhere('username', $id);
        });
    }
}