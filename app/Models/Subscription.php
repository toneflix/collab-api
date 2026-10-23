<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid period on a plan. Paying again before it ends extends it.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan',
        'amount',
        'starts_at',
        'ends_at',
        'transaction_id',
        'plan_id',
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

    /**
     * The plan this period is on. Named planModel because `plan` is the slug column.
     */
    public function planModel(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
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
