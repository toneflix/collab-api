<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The terms a royal earns on from the people they invite. One is active per royal at a time.
 */
class RoyaltyContract extends Model
{
    protected $fillable = [
        'user_id',
        'royalty_invite_id',
        'rate',
        'months',
        'note',
        'created_by_id',
        'ended_by_id',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'months' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(RoyaltyInvite::class, 'royalty_invite_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    public function isActive(): bool
    {
        return ! $this->ends_at || $this->ends_at->isFuture();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where(
            fn(Builder $q) => $q
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', now())
        );
    }
}
