<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An admin's invitation to join the royalty program on the terms it holds.
 */
class RoyaltyInvite extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'email',
        'name',
        'rate',
        'months',
        'code',
        'note',
        'token',
        'user_id',
        'invited_by_id',
        'expires_at',
        'accepted_at',
        'cancelled_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'months' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(RoyaltyContract::class);
    }

    public function status(): string
    {
        return match (true) {
            (bool) $this->accepted_at => self::ACCEPTED,
            (bool) $this->cancelled_at => self::CANCELLED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    /**
     * Invites that can still be accepted.
     */
    public function scopeOpen(Builder $query): void
    {
        $query
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now());
    }

    public function scopeStatus(Builder $query, ?string $status): void
    {
        match ($status) {
            self::PENDING => $query->open(),
            self::ACCEPTED => $query->whereNotNull('accepted_at'),
            self::CANCELLED => $query->whereNotNull('cancelled_at'),
            self::EXPIRED => $query
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->where('expires_at', '<=', now()),
            default => null,
        };
    }
}
