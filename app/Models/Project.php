<?php

namespace App\Models;

use App\Contracts\CanBeTakenDown;
use App\Models\Scopes\HideDeactivatedAccounts;
use App\Models\Scopes\HideReportedModels;
use App\Models\Scopes\RestrictBlockedUserScope;
use App\Traits\ModelCanExtend;
use App\Traits\TakesDown;
use BeyondCode\Comments\Traits\HasComments;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Toneflix\LaravelFileable\Traits\Fileable;
use Toneflix\SocialInteractions\Traits\HasSocialInteractions;

/**
 * @property Collection $collaborators
 * @property Release $release
 * @property User $user
 */
#[ScopedBy([
    RestrictBlockedUserScope::class,
    HideReportedModels::class,
    HideDeactivatedAccounts::class,
])]
class Project extends Model implements CanBeTakenDown
{
    use Fileable;
    use HasComments;
    use HasFactory;
    use HasSocialInteractions;
    use ModelCanExtend;
    use TakesDown;

    /**
     * Privacy settings anyone can see a project under: public opens its
     * studio read-only, public_preview only plays its tracks' preview mixes.
     */
    public const OPEN_PRIVACIES = ['public', 'public_preview'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'privacy',
        'tracks_public',
        'description',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy' => 'private',
        'tracks_public' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tracks_public' => 'boolean',
        ];
    }

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
            $model
                ->tracks()
                ->delete();
            $model
                ->comments()
                ->delete();
            $model
                ->comments()
                ->delete();
            $model
                ->collaborators()
                ->delete();
            $model
                ->socialInteractions()
                ->delete();
            $model
                ->socialInteractionSaves()
                ->delete();
        });

        static::updated(function (self $model) {
            $model
                ->releases()
                ->where('draft', true)
                ->update([
                    'title' => $model->title,
                    'privacy' => $model->privacy,
                    'description' => $model->description,
                ]);
        });

        static::addGlobalScopes([
            fn(Builder $query) => $query->withCount(['tracks', 'collaborators']),
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
        $query = $this->where('slug', $value);

        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->firstOrFail();
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
            return $this->collaborators
                ->where('accepted', true)
                ->contains('user_id', $user->id);
        }

        return $this
            ->collaborators()
            ->accepted()
            ->forUser($user)
            ->exists();
    }

    /**
     * Whether a user can work on this project's tracks: its owner and the
     * accepted collaborators.
     */
    public function canEdit(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id || $this->isCollaber($user);
    }

    /**
     * Open projects are visible to everyone; private ones to the people who
     * can edit them.
     */
    public function isVisibleTo(?User $user): bool
    {
        return in_array($this->privacy, self::OPEN_PRIVACIES, true) || ($user && $this->canEdit($user));
    }

    /**
     * Whether the user can open the project's studio: its people always, and
     * anyone, read-only, when the project is public.
     */
    public function opensStudioTo(?User $user): bool
    {
        return $this->privacy === 'public' || ($user && $this->canEdit($user));
    }

    /**
     * Limit the query to projects the user can see, matching isVisibleTo().
     */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->whereIn('projects.privacy', self::OPEN_PRIVACIES);

            if ($user) {
                $query->orWhere('projects.user_id', $user->id);
                $query->orWhereHas('collaborators', fn(Builder $q) => $q->accepted()->where('user_id', $user->id));
            }
        });
    }

    public function collaberData(User $user, $pending = false): ?ProjectCollaborator
    {
        return $this
            ->collaborators()
            ->accepted(! $pending)
            ->forUser($user)
            ->first();
    }

    public function ready(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->tracks()->ready()->exists(),
        );
    }

    /**
     * Every release of this project, one per version.
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * The latest release of this project, published or not.
     */
    public function release(): HasOne
    {
        return $this
            ->hasOne(Release::class)
            ->latest('id');
    }

    public function released(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->releases()->where('draft', false)->exists(),
        );
    }

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('release', function (Builder $q) use ($genre) {
            $q->whereHas('genre', function (Builder $q) use ($genre) {
                $q
                    ->where('slug', $genre)
                    ->when(is_numeric($genre), fn($q) => $q->orWhere('id', $genre));
            });
            $q->orWhereHas('tracks', function (Builder $qx) use ($genre) {
                $qx->whereHas('genre', function (Builder $q) use ($genre) {
                    $q
                        ->where('slug', $genre)
                        ->when(is_numeric($genre), fn($q) => $q->orWhere('id', $genre));
                });
            });
        });
    }

    public function scopeReleased(Builder $query, $released = true): void
    {
        if ($released === true) {
            $query->whereHas('releases', fn($q) => $q->whereDraft(false));
        } else {
            $query->whereDoesntHave('releases', fn($q) => $q->whereDraft(false));
        }
    }

    /**
     * Rank projects by the votes they got in the last given days, keeping projects without votes after them.
     */
    public function scopeTop(Builder $query, $top = true, int $days = 7): void
    {
        $scope = fn(Builder $q) => $q
            ->whereBetween('social_interactions.created_at', [now()->subDays($days), now()]);

        $direction = $top === true ? 'desc' : 'asc';
        $nulls = DB::getDriverName() === 'pgsql' ? ' nulls last' : '';

        $query
            ->withSum(['socialInteractions' => $scope], 'votes')
            ->orderByRaw("social_interactions_sum_votes {$direction}{$nulls}")
            ->latest('projects.id');
    }

    public function scopeCollaber(Builder $query, User|string $user, ?int $days = null): void
    {
        $id = is_string($user) ? $user : $user->id;

        // Grouped so the "or" does not bypass the other conditions on the query
        $query->where(function (Builder $query) use ($id) {
            $query->whereHas('collaborators', function (Builder $q) use ($id) {
                $q->accepted();
                $q->whereHas('user', function (Builder $qx) use ($id) {
                    $qx
                        ->where('users.username', $id)
                        ->when(is_numeric($id), fn($q) => $q->orWhere('users.id', $id));
                });
            });

            if (is_numeric($id)) {
                $query->orWhere('projects.user_id', $id);
            } else {
                $query->orWhereHas('user', fn(Builder $q) => $q->where('users.username', $id));
            }
        });

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
        return $this
            ->tracks()
            ->ready();
    }

    /**
     * Get the user that created the Project
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'project_collaborators',
            'project_id',
            'user_id'
        );
    }

    public function whereOwnedBy(Builder $query, int $userId): void
    {
        $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function moderationOwnerId(): ?int
    {
        return $this->user_id ? (int) $this->user_id : null;
    }

    public function moderationSummary(): array
    {
        $owner = User::query()
            ->withoutGlobalScopes()
            ->find($this->user_id);

        return [
            'title' => (string) $this->title,
            'subtitle' => $owner ? "Project by {$owner->fullname}" : 'Project',
            'image' => $this->images['image'] ?? null,
            'link' => in_array($this->privacy, self::OPEN_PRIVACIES, true) ? '/projects/' . $this->slug : null,
        ];
    }
}
