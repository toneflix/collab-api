<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One entry in an assistant chat: what the admin wrote, the assistant's
 * answer, a tool it used, or an action waiting for the admin to confirm.
 *
 * @property string $role
 * @property array<string, mixed>|null $data
 */
class AssistantMessage extends Model
{
    public const ADMIN = 'admin';

    public const ASSISTANT = 'assistant';

    public const TOOL = 'tool';

    public const ACTION = 'action';

    /** A new message moves its chat up the list */
    protected $touches = ['chat'];

    protected $fillable = [
        'role',
        'content',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AssistantChat::class, 'assistant_chat_id');
    }

    public function action(): HasOne
    {
        return $this->hasOne(AssistantAction::class);
    }
}
