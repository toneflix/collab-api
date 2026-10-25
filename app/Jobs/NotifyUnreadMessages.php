<?php

namespace App\Jobs;

use App\Broadcasting\Channels;
use App\Models\User;
use App\Notifications\NewMessagesNotification;
use App\Services\Conversations\Conversations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tells one user about the messages they haven't read in one conversation.
 * Only one waits per user and conversation at a time, and a user hears about
 * a conversation at most once per delay, so a busy chat makes one
 * notification instead of many.
 */
class NotifyUnreadMessages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public int $userId,
        public string $rootType,
        public int|string $rootId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->rootType}:{$this->rootId}";
    }

    public function uniqueFor(): int
    {
        return static::waitMinutes() * 60 + 600;
    }

    /**
     * Minutes to wait before notifying.
     */
    public static function waitMinutes(): int
    {
        return max(0, (int) config('settings.notifications.message_delay', 5));
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        $root = class_exists($this->rootType) ? $this->rootType::query()->find($this->rootId) : null;

        if (! $user || ! $root || ! Channels::canView($user, $root)) {
            return;
        }

        $readAt = DB::table('conversation_reads')
            ->where(['user_id' => $user->id, 'root_type' => $root::class, 'root_id' => $root->getKey()])
            ->value('read_at');

        $unread = Conversations::messages($root)
            ->where('user_id', '!=', $user->id)
            ->when($readAt, fn($q) => $q->where('created_at', '>', $readAt));

        $count = (clone $unread)->count();
        if ($count === 0 || ! Cache::add('notified-messages:' . $this->uniqueId(), true, max(1, static::waitMinutes()) * 60)) {
            return;
        }

        $latest = (clone $unread)->latest('id')->first();
        $senders = User::query()
            ->whereIn('id', (clone $unread)->select('user_id')->distinct()->limit(5))
            ->limit(5)
            ->get()
            ->map(fn(User $u) => $u->fullname ?: $u->username)
            ->all();

        $subject = Conversations::subject($root);

        // The new summary covers the older unread one for this conversation.
        $user->unreadNotifications()
            ->where('type', NewMessagesNotification::class)
            ->where('data->path', "/messages/{$subject['type']}/{$subject['id']}")
            ->delete();

        $user->notify(new NewMessagesNotification(
            $subject['type'],
            $subject['id'],
            (string) $subject['title'],
            $count,
            $senders,
            $latest ? Conversations::preview($latest, 140) : '',
        ));
    }
}
