<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A creator's request to withdraw part of their earnings to their bank.
 *
 * @property PayoutStatus $status
 * @property Collection $destination
 * @property Carbon|null $paid_at
 * @property Carbon|null $reviewed_at
 */
class Payout extends Model
{
    protected $fillable = [
        'reference',
        'amount',
        'currency',
        'status',
        'destination',
        'transfer_code',
        'failure_reason',
        'reviewed_by',
        'reviewed_at',
        'paid_at',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'status' => PayoutStatus::class,
            'destination' => 'collection',
            'gateway_response' => 'array',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeStatus(Builder $query, PayoutStatus|string ...$statuses): void
    {
        $query->whereIn('status', array_map(fn ($s) => $s instanceof PayoutStatus ? $s->value : $s, $statuses));
    }
}
