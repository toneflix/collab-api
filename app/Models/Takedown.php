<?php

namespace App\Models;

use App\Services\Moderation\Reportables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Content a moderator took down, and the owner's appeal if they made one.
 *
 * @property string $status
 * @property string $reason
 * @property string|null $appeal
 */
class Takedown extends Model
{
    public const ACTIVE = 'active';

    public const APPEALED = 'appealed';

    public const RESTORED = 'restored';

    protected $fillable = [
        'model_type',
        'model_id',
        'moderator_id',
        'reason',
        'note',
        'status',
        'appeal',
        'appealed_at',
        'resolved_by',
        'resolution',
        'resolved_at',
    ];

    protected $attributes = [
        'status' => self::ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'appealed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Safety::class);
    }

    /**
     * Takedowns still in force, appealed or not.
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereIn('status', [self::ACTIVE, self::APPEALED]);
    }

    public function inForce(): bool
    {
        return in_array($this->status, [self::ACTIVE, self::APPEALED], true);
    }

    /**
     * The content this takedown is for, taken down or not.
     */
    public function item(): ?Model
    {
        return app(Reportables::class)
            ->find($this->model_type, $this->model_id);
    }
}
