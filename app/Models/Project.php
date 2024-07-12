<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use BeyondCode\Comments\Traits\HasComments;
use Coderflex\Laravisit\Concerns\HasVisits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use ToneflixCode\LaravelFileable\Traits\Fileable;
use ToneflixCode\SocialInteractions\Traits\HasSocialInteractions;

/**
 * @property Collection $collaborators
 */
class Project extends Model
{
    use Fileable;
    use HasComments;
    use HasFactory;
    use HasSocialInteractions;
    use HasVisits;
    use ModelCanExtend;

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
            $model->comments()->delete();
            $model->comments()->delete();
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
            fn (Builder $query) => $query->withCount(['tracks', 'collaborators']),
        ]);
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  string|null  $field
     * @return static
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

    public function isCollaber(User $user): bool
    {
        if ($this->relationLoaded('collaborators')) {
            return $this->collaborators->where('accepted', true)->contains($user);
        }

        return $this->collaborators()->accepted()->forUser($user)->exists();
    }

    public function ready(): Attribute
    {
        return Attribute::make(
            get: function () {
                $this->tracks()->ready()->exists();
            }
        );
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

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('release', function (Builder $q) use ($genre) {
            $q->whereHas('genre', function (Builder $q) use ($genre) {
                $q->where('id', $genre)->orWhere('slug', $genre);
            });
            $q->orWhereHas('tracks', function (Builder $qx) use ($genre) {
                $qx->whereHas('genre', function (Builder $q) use ($genre) {
                    $q->where('id', $genre)->orWhere('slug', $genre);
                });
            });
        });
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

    public function scopeCollaber(Builder $query, User|string $user, int $days = null): void
    {
        $id = is_string($user) ? $user : $user->id;

        $query->whereHas('collaborators', function (Builder $q) use ($id) {
            $q->accepted();
            $q->whereHas('user', function (Builder $qx) use ($id) {
                $qx->where('users.id', $id)->orWhere('users.username', $id);
            });
        });
        $query->orWhere('projects.user_id', $id);

        if ($days) {
            $query->whereBetween('updated_at', [now()->subDays($days), now()]);
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
     * Get all of the tracks for the Project that are ready
     */
    public function tracksReady(): HasMany
    {
        return $this->tracks()->ready();
    }

    /**
     * Get the user that created the Project
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
