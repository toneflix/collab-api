<?php

namespace App\Services\Notifications;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;

/**
 * Push to the mobile apps through Firebase Cloud Messaging. The service
 * account comes from `FIREBASE_CREDENTIALS`, shared with the Firebase
 * realtime driver.
 */
class Fcm
{
    public static function credentials(): ?string
    {
        return config('services.firebase.credentials') ?: config('broadcasting.connections.firebase.credentials');
    }

    public static function enabled(): bool
    {
        return filled(static::credentials());
    }

    public static function messaging(): Messaging
    {
        $credentials = (string) static::credentials();
        $decoded = json_decode($credentials, true);

        return (new Factory)
            ->withServiceAccount(is_array($decoded) ? $decoded : $credentials)
            ->createMessaging();
    }
}
