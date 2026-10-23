<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid Premium period. Paying again before it ends extends it.
 */
class Subscription extends Model
{
    use HasFactory;

    /**
     * How long each plan lasts.
     *
     * @var array<string, string>
     */
    public const PLANS = [
        'monthly' => '1 month',
        'yearly' => '1 year',
    ];

    protected $fillable = [
        'plan',
        'amount',
        'starts_at',
        'ends_at',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
