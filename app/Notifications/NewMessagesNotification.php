<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;

/**
 * Messages a user hasn't read in one conversation, sent a little while after
 * the first of them so a busy chat makes one notification, not many.
 */
class NewMessagesNotification extends ActivityNotification
{
    /**
     * @param  array<int, string>  $senders
     */
    public function __construct(
        protected string $conversationType,
        protected int|string $conversationId,
        protected string $title,
        protected int $count,
        protected array $senders,
        protected string $preview = '',
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::MESSAGES;
    }

    protected function key(): string
    {
        return $this->count === 1 ? 'messages.one' : 'messages.many';
    }

    protected function params(User $notifiable): array
    {
        $names = collect($this->senders)->take(2);
        $others = count($this->senders) - $names->count();

        return [
            'title' => $this->title,
            'count' => $this->count,
            'senders' => $names->join(', ', ' and ') . ($others > 0 ? __(' and :0 more', [$others]) : ''),
            'preview' => $this->preview,
        ];
    }

    protected function path(): string
    {
        return "/messages/{$this->conversationType}/{$this->conversationId}";
    }
}
