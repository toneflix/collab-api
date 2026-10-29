<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A listener whose paid streams in a month mostly went to one creator. Their
 * streams to that creator are held from the payout until an admin clears them.
 *
 * @property Carbon $month
 */
class StreamCheck extends Model
{
    public const PENDING = 'pending';

    public const CLEARED = 'cleared';

    public const CONFIRMED = 'confirmed';

    public const STATUSES = [self::PENDING, self::CLEARED, self::CONFIRMED];

    protected $fillable = [
        'user_id',
        'owner_id',
        'month',
        'plays',
        'share',
        'status',
        'reviewed_by',
        'reviewed_at',
        'top_up',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'plays' => 'integer',
            'share' => 'float',
            'reviewed_at' => 'datetime',
            'top_up' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this
            ->belongsTo(User::class)
            ->withoutGlobalScopes();
    }

    public function owner(): BelongsTo
    {
        return $this
            ->belongsTo(User::class, 'owner_id')
            ->withoutGlobalScopes();
    }

    public function reviewer(): BelongsTo
    {
        return $this
            ->belongsTo(User::class, 'reviewed_by')
            ->withoutGlobalScopes();
    }

    /**
     * Checks that still hold their streams.
     */
    public function scopeHolding(Builder $query): void
    {
        $query->where('status', '!=', self::CLEARED);
    }
}
