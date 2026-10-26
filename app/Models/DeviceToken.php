<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An installed app that receives push through Firebase Cloud Messaging.
 */
class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = [
        'user_id',
        'token',
        'platform',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
