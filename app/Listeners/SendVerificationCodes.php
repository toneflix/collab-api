<?php

namespace App\Listeners;

use App\Enums\VerificationChannel;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * Sends a new user a code for each contact detail the app asks them to confirm.
 */
class SendVerificationCodes
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        foreach (VerificationChannel::cases() as $channel) {
            if ($channel->pending($user)) {
                $channel->send($user);
            }
        }
    }
}
