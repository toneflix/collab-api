<?php

namespace App\Listeners;

use App\Broadcasting\Channels;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\Realtime\Realtime;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Pushes a new in-app notification to the user's open tabs.
 */
class NotificationSentToApp
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database'
            || ! $event->notification instanceof ActivityNotification
            || ! $event->notifiable instanceof User
            || ! $event->response instanceof DatabaseNotification) {
            return;
        }

        Realtime::send(Channels::user($event->notifiable), 'notification.created', [
            'notification' => (new NotificationResource($event->response))->resolve(),
        ]);
    }
}
