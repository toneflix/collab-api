<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation between an admin and the admin assistant.
 *
 * @property string $status
 */
class AssistantChat extends Model
{
    public const IDLE = 'idle';

    public const THINKING = 'thinking';

    public const FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'error',
    ];

    protected $attributes = [
        'status' => self::IDLE,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AssistantAction::class);
    }

    public function isThinking(): bool
    {
        return $this->status === self::THINKING;
    }
}
