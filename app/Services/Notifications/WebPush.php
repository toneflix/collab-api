<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush as WebPushClient;

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

    /**
     * The client that delivers push messages. Missing PHP extensions are
     * logged instead of raised, so push still works without GMP or BCMath.
     */
    public static function client(): WebPushClient
    {
        $vapid = config('webpush.vapid');

        $auth = static::enabled() ? ['VAPID' => array_filter([
            'subject' => $vapid['subject'] ?: config('app.frontend_url'),
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key'],
            'pemFile' => $vapid['pem_file'] ?? null,
        ])] : [];

        $client = Http::timeout(30)
            ->withOptions(config('webpush.client_options', []))
            ->buildClient();

        return (new WebPushClient($auth, [], $client, logger: Log::channel()))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding(config('webpush.automatic_padding', true));
    }
}
