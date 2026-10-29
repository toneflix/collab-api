<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed-in listener's plays of one track on one day. Paid plays earn the
 * track's owner a share of that month's stream pool.
 */
class TrackPlay extends Model
{
    protected $fillable = [
        'track_id',
        'user_id',
        'owner_id',
        'played_on',
        'seconds',
        'paid',
    ];

    protected function casts(): array
    {
        return [
            'played_on' => 'date',
            'seconds' => 'integer',
            'paid' => 'boolean',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
