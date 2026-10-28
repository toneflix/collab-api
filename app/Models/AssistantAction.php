<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the admin assistant proposed, which runs only once the admin
 * confirms it. It also keeps what came of it.
 *
 * @property string $status
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $result
 */
class AssistantAction extends Model
{
    public const PENDING = 'pending';

    /** Confirmed and being carried out */
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Minutes an action waits for the admin before it can no longer run */
    public const EXPIRES_AFTER = 60;

    protected $fillable = [
        'assistant_chat_id',
        'assistant_message_id',
        'user_id',
        'tool',
        'input',
        'summary',
        'status',
        'result',
        'error',
        'decided_at',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AssistantChat::class, 'assistant_chat_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'assistant_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function hasExpired(): bool
    {
        return $this->created_at?->lt(now()->subMinutes(self::EXPIRES_AFTER)) ?? false;
    }
}
