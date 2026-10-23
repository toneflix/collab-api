<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use BeyondCode\Comments\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Toneflix\LaravelFileable\Traits\Fileable;

class ProjectTrack extends Model
{
    use Fileable;
    use HasComments;
    use HasFactory;
    use ModelCanExtend;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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
            'meta' => \Illuminate\Database\Eloquent\Casts\AsCollection::class,
        ];
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
            $model->stems()->delete();
            $model->assets()->delete();
            $model->releasedTrack()->delete();
        });

        static::updated(function (self $model) {
            if ($model->releasedTrack) {
                $model->releasedTrack->title = $model->title;
                $model->releasedTrack->genre_id = $model->genre_id;
                $artists = [];
                if ($model->isDirty('artist_id')) {
                    $artists[$model->artist_id] = ['type' => 'primary'];
                    $model->releasedTrack->artists()->sync($artists);
                }
                $model->releasedTrack->saveQuietly();
            }
        });
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
        return $this->belongsTo(Genre::class)->withDefault(fn () => Genre::first() ?? [
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
     * Get the released track for this project Track if available
     */
    public function releasedTrack(): HasOne
    {
        return $this->hasOne(Track::class);
    }

    /**
     * Get the stems for the Track
     */
    public function stems(): HasMany
    {
        return $this->hasMany(ProjectTrackStem::class, 'track_id');
    }

    public function scopeReady(Builder $query): void
    {
        $query->whereHas('stems', fn (Builder $q) => $q->whereNotNull('src'));
    }

    /**
     * Get the user that created the Project
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
