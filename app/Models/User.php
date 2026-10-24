<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\Feature;
use App\Models\Scopes\RestrictBlockedUserScope;
use App\Notifications\SendCode;
use App\Traits\ModelCanExtend;
use BeyondCode\Comments\Contracts\Commentator;
use Coderflex\Laravisit\Models\Visit;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Overtrue\LaravelFollow\Traits\Followable;
use Overtrue\LaravelFollow\Traits\Follower;
use Spatie\Permission\Traits\HasRoles;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;
use Toneflix\ApprovableNotifications\Traits\ApprovableNotifiable;
use Toneflix\LaravelFileable\Traits\Fileable;
use Toneflix\SocialInteractions\Models\SocialInteraction;
use Toneflix\SocialInteractions\Traits\CanSocialInteract;
use Valorin\Random\Random;

#[ScopedBy([RestrictBlockedUserScope::class])]
class User extends Authenticatable implements Commentator
{
    use ApprovableNotifiable;
    use CanSocialInteract;
    use Fileable;
    use Followable;
    use Follower;
    use HasApiTokens;
    use HasFactory;
    use HasJsonRelationships;
    use HasRoles;
    use ModelCanExtend;
    use Notifiable;

    public $eraseOnDelete = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'firstname',
        'lastname',
        'address',
        'country',
        'state',
        'city',
        'email',
        'phone',
        'username',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        // 'data',
        'password',
        'remember_token',
        'access_data',
        'email_verify_code',
        'phone_verify_code',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'curator' => false,
        'verified' => false,
        'reputation' => 0,
        'data' => '{
            "settings": {
                "notifications": {
                    "email": true
                }
            },
            "eraseOnDelete": false
        }',
        'access_data' => '{}',
    ];

    /**
     * The attributes to be appended
     *
     * @var array
     */
    protected $appends = [
        'user_data',
        'fullname',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'curator' => 'boolean',
            'verified' => 'boolean',
            'reputation' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'data' => AsCollection::class,
            'access_data' => AsCollection::class,
            'last_attempt' => 'datetime',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
        ];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'avatar',
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::creating(function (User $model) {
            $model->username ??= $model->generateUsername($model->firstname);
        });

        static::deleting(function (User $model) {
            $model->projects()->delete();
            $model->followers()->delete();
            $model->followings()->delete();
            $model->recommendings()->delete();
            $model->viewingHistory()->delete();
            $model->recommendations()->delete();
            $model->socialInteracts()->delete();
            $model->savedSocialInteracts()->delete();
            // $model->collabProjects();
            $model->approvableNotifications()->delete();

            if (@$model->data['eraseOnDelete'] === true) {
                $model->releases()->delete();
                $model->artists()->delete();
            }
        });

        static::addGlobalScopes([
            fn (Builder $query) => $query->withCount(['projects', 'collabProjects']),
        ]);
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->where('username', $value);

        if (is_numeric($value)) {
            $query->orWhere('id', $value);
        }

        return $query->firstOrFail();
    }

    /**
     * Get the user's blocklist.
     */
    public function blocklist(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    /**
     * Get the user's fullname.
     *
     * @return string
     */
    protected function fullname(): Attribute
    {
        return Attribute::make(
            get: fn () => collect([$this->firstname, $this->lastname])->join(' '),
        );
    }

    public function hasVerifiedPhone()
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Send the email verification message
     */
    public function sendOtpNotification()
    {
        $this->last_attempt = now();
        $this->otp = Random::otp(6);
        $this->save();

        $this->notify(new SendCode($this->otp, 'otp'));
    }

    /**
     * Send the email verification message
     */
    public function sendEmailVerificationNotification()
    {
        $this->last_attempt = now();
        $this->email_verify_code = Random::otp(6);
        $this->save();

        $this->notify(new SendCode($this->email_verify_code, 'verify'));
    }

    /**
     * Send the phone verification message
     */
    public function sendPhoneVerificationNotification()
    {
        $this->last_attempt = now();
        $this->phone_verify_code = Random::otp(6);
        $this->save();

        $this->notify(new SendCode($this->phone_verify_code, 'verify-phone'));
    }

    public function markEmailAsVerified()
    {
        $this->last_attempt = null;
        $this->email_verify_code = null;
        $this->email_verified_at = now();
        $this->save();

        if ($this->wasChanged('email_verified_at')) {
            return true;
        }

        return false;
    }

    public function markPhoneAsVerified()
    {
        $this->last_attempt = null;
        $this->phone_verify_code = null;
        $this->phone_verified_at = now();
        $this->save();

        if ($this->wasChanged('phone_verified_at')) {
            return true;
        }

        return false;
    }

    /**
     * Get all of the artists created by the User
     */
    public function artists(): HasMany
    {
        return $this->hasMany(Artist::class);
    }

    public function collaborators()
    {
        return $this->hasManyThrough(
            User::class,
            ProjectCollaborator::class,
            'project_id',
            'id',
            'id',
            'user_id'
        )
            ->where('project_collaborators.user_id', '!=', $this->id)
            ->where('project_collaborators.accepted', true);
    }

    /**
     * Get all of the collabProjects the User is associated with
     */
    public function collabProjects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'project_collaborators',
            'user_id',
            'project_id'
        )->wherePivot('accepted', true);
    }

    /**
     * Get the user's fullname.
     *
     * @return string
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fullname,
        );
    }

    /**
     * Check if a comment for a specific model needs to be approved.
     *
     * @param  mixed  $model
     */
    public function needsCommentApproval($model): bool
    {
        return false;
    }

    /**
     * Get all of the interaction recieved by the User's projects
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The user's own sounds, for adding to tracks.
     */
    public function sounds(): HasMany
    {
        return $this->hasMany(Sound::class);
    }

    /**
     * The Freesound account the user connected, if any.
     */
    public function freesound(): HasOne
    {
        return $this->hasOne(FreesoundConnection::class);
    }

    /**
     * Get the user's fullname.
     *
     * @return string
     */
    protected function projectsCount(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value + $this->collab_projects_count,
        );
    }

    public function projectInteractions(): HasManyThrough
    {
        return $this->hasManyThrough(
            SocialInteraction::class,
            Project::class,
            'user_id',
            'interactable_id',
            'id',
            'id'
        )->where('social_interactions.interactable_type', Project::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->latest();
    }

    public function recommendings(): MorphMany
    {
        return $this->morphMany(Recommendation::class, 'recommendable')->latest();
    }

    /**
     * Get all of the releases created by the User
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function scopeCreator(Builder $query): void
    {
        $query->whereHas('projects');
        $query->orWhereHas('collabProjects');
    }

    public function scopeCollabing(Builder $query, User|string $user): void
    {
        $id = is_string($user) ? $user : $user->id;

        $query->whereHas('projects', function ($query) use ($id) {
            $query->whereHas('users', function ($query) use ($id) {
                $query->where('user_id', $id);
            });
        })->where('users.id', '!=', $id);
    }

    public function scopeForProject(Builder $query, Project|string $project): void
    {
        $id = is_string($project) ? $project : $project->id;

        // Only compare against the numeric id when the value is numeric (Postgres rejects slugs there)
        $scope = function (Builder $q) use ($id) {
            $q->where(function (Builder $q) use ($id) {
                $q->where('projects.slug', $id);
                if (is_numeric($id)) {
                    $q->orWhere('projects.id', $id);
                }
            });
        };

        // Group the two conditions so they don't bypass the other filters on the query
        $query->where(function (Builder $q) use ($scope) {
            $q->whereHas('projects', $scope)->orWhereHas('collabProjects', $scope);
        });
    }

    public function scopeGenre(Builder $query, string $genre): void
    {
        $query->whereHas('releases', function (Builder $q) use ($genre) {
            $q->whereHas('genre', function (Builder $q) use ($genre) {
                $q->where('slug', $genre)->when(is_numeric($genre), fn ($q) => $q->orWhere('id', $genre));
            });
            $q->orWhereHas('tracks', function (Builder $qx) use ($genre) {
                $qx->whereHas('genre', function (Builder $q) use ($genre) {
                    $q->where('slug', $genre)->when(is_numeric($genre), fn ($q) => $q->orWhere('id', $genre));
                });
            });
        });
    }

    public function scopeOrderByUserProjectVotesLastDays(Builder $query, int $days = 7, string $direction = 'desc')
    {
        $scope = fn (Builder $q) => $q
            ->whereBetween('social_interactions.created_at', [now()->subDays($days), now()]);

        $query
            ->whereHas('projectInteractions', $scope)
            ->withMax(['projectInteractions' => $scope], 'votes')
            ->orderBy('project_interactions_max_votes', $direction);
    }

    public function scopeOrderByFollowersLastDays(Builder $query, int $days = 7, string $direction = 'desc')
    {
        $scope = fn (Builder $q) => $q
            ->whereBetween('followables.created_at', [now()->subDays($days), now()]);

        $query
            ->whereHas('followers', $scope)
            ->withCount(['followers' => $scope])
            ->orderBy('followers_count', $direction);
    }

    /**
     * Get all of the releases created by the User
     */
    public function tracks(): HasManyThrough
    {
        return $this->hasManyThrough(Track::class, Release::class);
    }

    /**
     * Get the user's Premium periods
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->latest('ends_at');
    }

    /**
     * Whether the user has an active paid subscription.
     */
    public function isPremium(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->subscriptions()->active()->exists(),
        )->shouldCache();
    }

    /**
     * The plan the user is on: their active subscription's plan, or the default plan.
     */
    public function currentPlan(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->subscriptions()->active()->whereNotNull('plan_id')->with('planModel')->first()?->planModel
                ?? Plan::default(),
        )->shouldCache();
    }

    /**
     * What the user's plan gives for a feature.
     */
    public function feature(Feature $feature): bool|int
    {
        return $this->current_plan->feature($feature);
    }

    /**
     * The bank account the creator withdraws earnings to.
     */
    public function payoutAccount(): HasOne
    {
        return $this->hasOne(PayoutAccount::class);
    }

    /**
     * The creator's withdrawals.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * Payments other people made that this creator earns from.
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recipient_id')->where('status', 'complete');
    }

    /**
     * Get all of the transactions for the User
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the user's fullname.
     *
     * @return string
     */
    protected function userData(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->data,
            set: fn ($value) => is_array($value)
                ? json_encode($value, JSON_FORCE_OBJECT)
                : $value,
        );
    }

    public function viewingHistory()
    {
        return $this->hasMany(Visit::class, 'data->user');
    }
}
