<?php

namespace App\Services\Realtime;

use App\Services\Settings\SettingsStore;

/**
 * Which realtime driver is on. Admins pick it; each driver's keys come from `.env`.
 */
class RealtimeSettings extends SettingsStore
{
    public const DRIVERS = ['none', 'reverb', 'pusher', 'ably', 'firebase'];

    protected const CACHE_KEY = 'realtime::settings';

    public const FIELDS = [
        'realtime_driver' => ['Realtime Driver', null, 'none, reverb, pusher, ably or firebase'],
    ];

    public const KEYS = [
        'reverb' => [
            'REVERB_APP_ID' => ['Reverb App ID', 'broadcasting.connections.reverb.app_id'],
            'REVERB_APP_KEY' => ['Reverb Key', 'broadcasting.connections.reverb.key'],
            'REVERB_APP_SECRET' => ['Reverb Secret', 'broadcasting.connections.reverb.secret'],
            'REVERB_HOST' => ['Reverb Host', 'broadcasting.connections.reverb.options.host'],
            'REVERB_PORT' => ['Reverb Port', 'broadcasting.connections.reverb.options.port', false],
            'REVERB_SCHEME' => ['Reverb Scheme', 'broadcasting.connections.reverb.options.scheme', false],
        ],
        'pusher' => [
            'PUSHER_APP_ID' => ['Pusher App ID', 'broadcasting.connections.pusher.app_id'],
            'PUSHER_APP_KEY' => ['Pusher Key', 'broadcasting.connections.pusher.key'],
            'PUSHER_APP_SECRET' => ['Pusher Secret', 'broadcasting.connections.pusher.secret'],
            'PUSHER_APP_CLUSTER' => ['Pusher Cluster', 'broadcasting.connections.pusher.options.cluster'],
        ],
        'ably' => [
            'ABLY_KEY' => ['Ably API Key', 'broadcasting.connections.ably.key'],
        ],
        'firebase' => [
            'FIREBASE_CREDENTIALS' => ['Firebase Service Account', 'broadcasting.connections.firebase.credentials'],
            'FIREBASE_DATABASE_URL' => ['Firebase Database URL', 'broadcasting.connections.firebase.database_url'],
            'FIREBASE_API_KEY' => ['Firebase Web API Key', 'services.firebase.api_key'],
            'FIREBASE_PROJECT_ID' => ['Firebase Project ID', 'services.firebase.project_id'],
            'FIREBASE_APP_ID' => ['Firebase App ID', 'services.firebase.app_id', false],
        ],
    ];

    protected static function fallback(string $key, array $field): ?string
    {
        return $key === 'realtime_driver' ? config('services.realtime.driver') : $field[1];
    }

    protected static function normalize(array $settings): array
    {
        if (! in_array($settings['realtime_driver'], self::DRIVERS, true)) {
            $driver = config('services.realtime.driver');
            $settings['realtime_driver'] = in_array($driver, self::DRIVERS, true) ? $driver : 'none';
        }

        return $settings;
    }

    public static function driver(): string
    {
        return self::get('realtime_driver', 'none');
    }
}
