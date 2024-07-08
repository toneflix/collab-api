<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use Coderflex\Laravisit\Concerns\HasVisits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use ToneflixCode\LaravelFileable\Traits\Fileable;
use ToneflixCode\SocialInteractions\Traits\HasSocialInteractions;

class Project extends Model
{
    use Fileable;
    use HasFactory;
    use HasSocialInteractions;
    use ModelCanExtend;
    use HasVisits;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'privacy',
        'description',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy' => 'private',
    ];

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'artwork',
        ], 'default', true, false);
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });

        static::deleting(function (self $model) {
            $model->tracks()->delete();
            $model->collaborators()->delete();
            $model->socialInteractions()->delete();
            $model->socialInteractionSaves()->delete();
        });

        static::updated(function (self $model) {
            if ($model->release) {
                $model->release->title = $model->title;
                $model->release->privacy = $model->privacy;
                $model->release->description = $model->description;
                $model->release->saveQuietly();
            }
        });

        static::addGlobalScopes([
            fn (Builder $query) => $query->withCount(['tracks', 'collaborators'])
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
     * Get all of the collaborators for the Project
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(ProjectCollaborator::class);
    }

    /**
     * Get the release for this Project if available
     */
    public function release(): HasOne
    {
        return $this->hasOne(Release::class);
    }

    public function released(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->release && $this->release->draft !== true,
        );
    }

    public function scopeReleased(Builder $query, $released = true): void
    {
        if ($released === true) {
            $query->whereHas('release', fn ($q) => $q->whereDraft(false));
        } else {
            $query->whereDoesntHave('release');
            $query->orWhereHas('release', fn ($q) => $q->whereDraft(true));
        }
    }

    public function scopeTop(Builder $query, $top = true, int $days = 7): void
    {
        $scope = fn (Builder $q) => $q
            ->whereBetween('social_interactions.created_at', [now()->subDays($days), now()]);

        $query->withSum(['socialInteractions' => $scope], 'votes');
        $query->whereHas('socialInteractions', $scope);

        if ($top === true) {
            $query->latest('social_interactions_sum_votes');
        } else {
            $query->oldest('social_interactions_sum_votes');
        }
    }

    public function scopeOrderByLastDays(Builder $query, int $days = 7, string $direction = 'desc')
    {
        $query
            ->whereBetween('created_at', [now()->subDays($days), now()])
            ->orderBy('created_at', $direction);
    }

    /**
     * Get all of the tracks for the Project
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(ProjectTrack::class);
    }

    /**
     * Get all of the tracks for the Project
     */
    public function tracksReady(): HasMany
    {
        return $this->tracks()->ready();
    }

    public function ready(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tracks()->ready()->exists(),
        );
    }
}