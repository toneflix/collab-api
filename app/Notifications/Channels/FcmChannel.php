<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Push to a user's installed apps through Firebase Cloud Messaging. Tokens
 * Firebase no longer knows are removed. Failures are reported and never stop
 * the other channels.
 */
class FcmChannel
{
    /** Firebase takes at most 500 tokens per multicast. */
    public const BATCH = 500;

    public function send(User $notifiable, Notification $notification): void
    {
        try {
            /** @var CloudMessage $message */
            $message = $notification->toFcm($notifiable);

            $messaging = app(Messaging::class);

            $notifiable
                ->deviceTokens()
                ->chunkById(static::BATCH, fn(Collection $devices) => $this->sendTo($messaging, $message, $devices));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Collection<int, DeviceToken>  $devices
     */
    protected function sendTo(Messaging $messaging, CloudMessage $message, Collection $devices): void
    {
        $tokens = $devices
            ->pluck('token')
            ->all();

        $report = $messaging->sendMulticast($message, $tokens);
        $stale = array_merge($report->invalidTokens(), $report->unknownTokens());

        if ($stale !== []) {
            DeviceToken::query()
                ->whereIn('token', $stale)
                ->delete();
        }
    }
}
