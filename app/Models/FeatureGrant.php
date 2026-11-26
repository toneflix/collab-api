<?php

namespace App\Models;

use App\Enums\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan feature an admin gave one account, whatever plan it is on.
 * No expiry date means it lasts until revoked.
 */
class FeatureGrant extends Model
{
    protected $fillable = [
        'user_id',
        'feature',
        'granted_by_id',
        'reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'feature' => Feature::class,
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this
            ->belongsTo(User::class, 'granted_by_id')
            ->withoutGlobalScopes();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where(
            fn(Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()),
        );
    }
}
