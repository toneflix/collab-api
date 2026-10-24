<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's AI credits: plan credits used this month and bought credits left.
 */
class AiWallet extends Model
{
    protected $attributes = [
        'plan_used' => 0,
        'purchased' => 0,
    ];

    protected $fillable = [
        'user_id',
        'period',
        'plan_used',
        'purchased',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'plan_used' => 'integer',
            'purchased' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
