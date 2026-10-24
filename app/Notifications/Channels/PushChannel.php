<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Browser push. A push service that is down or a bad subscription is reported
 * and never stops the other channels or the request that sent the notification.
 */
class PushChannel extends WebPushChannel
{
    public function send(mixed $notifiable, Notification $notification): array
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
