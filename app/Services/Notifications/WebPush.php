<?php

namespace App\Services\Notifications;

/**
 * Browser push setup. The VAPID keys come from `.env`
 * (`php artisan webpush:vapid` creates them).
 */
class WebPush
{
    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /**
     * What a browser needs to subscribe.
     *
     * @return array{enabled: bool, publicKey: string|null}
     */
    public static function clientConfig(): array
    {
        return [
            'enabled' => static::enabled(),
            'publicKey' => static::enabled() ? config('webpush.vapid.public_key') : null,
        ];
    }
}
