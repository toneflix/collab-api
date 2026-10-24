<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One change to a user's AI credits: a purchase, a spend, a refund, a grant or an admin's deduction.
 */
class AiCreditEntry extends Model
{
    public const PURCHASE = 'purchase';

    public const SPEND = 'spend';

    public const REFUND = 'refund';

    public const GRANT = 'grant';

    public const DEDUCT = 'deduct';

    protected $fillable = [
        'user_id',
        'type',
        'credits',
        'plan_credits',
        'purchased_credits',
        'description',
        'refund_of',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'plan_credits' => 'integer',
            'purchased_credits' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the credits were for: an AI job, or the payment that bought them.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function refund(): HasOne
    {
        return $this->hasOne(self::class, 'refund_of');
    }
}
