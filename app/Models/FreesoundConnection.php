<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Freesound account someone connected, used to download original files.
 *
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 */
class FreesoundConnection extends Model
{
    protected $fillable = ['user_id', 'username', 'access_token', 'refresh_token', 'expires_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->subMinute()->isPast();
    }
}
