<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One settled month of stream earnings.
 */
class StreamPeriod extends Model
{
    public const SETTLING = 'settling';

    public const SETTLED = 'settled';

    protected $fillable = [
        'month',
        'status',
        'revenue',
        'share',
        'pool',
        'plays',
        'weighted',
        'rate',
        'earners',
        'paid_out',
        'weights',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'revenue' => 'float',
            'share' => 'float',
            'pool' => 'float',
            'plays' => 'integer',
            'weighted' => 'float',
            'rate' => 'float',
            'earners' => 'integer',
            'paid_out' => 'float',
            'weights' => 'array',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * The earnings this month paid out.
     */
    public function earnings(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactable');
    }
}
