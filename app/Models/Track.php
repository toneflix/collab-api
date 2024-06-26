<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use ToneflixCode\LaravelFileable\Traits\Fileable;

class Track extends Model
{
    use HasFactory, Fileable, ModelCanExtend;

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
            'image' => 'artwork',
            'audio' => 'music_tracks',
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::saving(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });
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
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class)->withDefault(fn () => Genre::first() ?? [
            'id' => null,
            'title' => 'Unknown',
            'description' =>  'Unknown',
        ]);
    }

    /**
     * Get the release that owns the Track
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class)->withDefault(fn () => [
            'title' => 'Unknown'
        ]);
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
}