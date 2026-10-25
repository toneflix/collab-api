<?php

namespace App\Models;

use App\Models\Scopes\RestrictBlockedUserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Overtrue\LaravelFollow\Traits\Followable as Flb;
use Overtrue\LaravelFollow\Traits\Follower as Flw;

/**
 * @property User $user
 */
class Block extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'blockable_type',
        'blockable_id',
        'reason',
    ];

    public static $allowedReasons = [
        'dmca',
        'spam',
        'hate',
        'abuse',
        'violent',
        'privacy',
        'sensitive',
        'self-harm',
        'copyright',
        'child-safety',
    ];

    public static function boot(): void
    {
        parent::boot();
        static::created(function (self $block) {
            // Unfollow each other
            if (in_array(Flb::class, class_uses($block->blockable)) && in_array(Flw::class, class_uses($block->user))) {
                $block->user->unfollow($block->blockable);
            }

            if (in_array(Flw::class, class_uses($block->blockable)) && in_array(Flb::class, class_uses($block->user))) {
                $block->blockable->unfollow($block->user);
            }
        });
    }

    public function blockable(): MorphTo
    {
        return $this
            ->morphTo()
            ->withoutGlobalScopes([RestrictBlockedUserScope::class]);
    }

    public function name(): Attribute
    {
        return Attribute::make(
            fn() => $this->blockable->fullname ?? $this->blockable->name ?? $this->blockable->title ?? 'This user'
        );
    }

    /**
     * Get the user that made the blocking
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, Model $model)
    {
        $query
            ->whereBlockableType($model->getMorphClass())
            ->whereBlockableId($model->getKey());
    }
}
