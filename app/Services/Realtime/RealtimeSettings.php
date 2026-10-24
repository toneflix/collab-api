<?php

namespace App\Services\Realtime;

use App\Services\Settings\SettingsStore;

/**
 * Realtime settings, kept in the `configurations` table so admins can change
 * them. Secret values are encrypted. Anything left empty falls back to the
 * matching config value (and so `.env`), so a server can also be set up there.
 */
class RealtimeSettings extends SettingsStore
{
    public const DRIVERS = ['none', 'reverb', 'pusher', 'ably', 'firebase'];

    protected const CACHE_KEY = 'realtime::settings';

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $defaults = null;

    /**
     * Every setting: key => [title, secret, config fallback, hint].
     *
     * @var array<string, array{0: string, 1: bool, 2: string|null, 3?: string}>
     */
    public const FIELDS = [
        'realtime_driver' => ['Realtime Driver', false, null, 'none, reverb, pusher, ably or firebase'],

        'reverb_app_id' => ['Reverb App ID', false, 'broadcasting.connections.reverb.app_id'],
        'reverb_key' => ['Reverb Key', false, 'broadcasting.connections.reverb.key'],
        'reverb_secret' => ['Reverb Secret', true, 'broadcasting.connections.reverb.secret'],
        'reverb_host' => ['Reverb Host', false, 'broadcasting.connections.reverb.options.host'],
        'reverb_port' => ['Reverb Port', false, 'broadcasting.connections.reverb.options.port'],
        'reverb_scheme' => ['Reverb Scheme', false, 'broadcasting.connections.reverb.options.scheme', 'https or http'],

        'pusher_app_id' => ['Pusher App ID', false, 'broadcasting.connections.pusher.app_id'],
        'pusher_key' => ['Pusher Key', false, 'broadcasting.connections.pusher.key'],
        'pusher_secret' => ['Pusher Secret', true, 'broadcasting.connections.pusher.secret'],
        'pusher_cluster' => ['Pusher Cluster', false, 'broadcasting.connections.pusher.options.cluster'],

        'ably_key' => ['Ably API Key', true, 'broadcasting.connections.ably.key', 'The full key, like appId.keyId:secret'],

        'firebase_credentials' => ['Firebase Service Account (JSON)', true, 'services.firebase.credentials'],
        'firebase_database_url' => ['Firebase Database URL', false, 'services.firebase.database_url'],
        'firebase_api_key' => ['Firebase Web API Key', false, 'services.firebase.api_key'],
        'firebase_project_id' => ['Firebase Project ID', false, 'services.firebase.project_id'],
        'firebase_app_id' => ['Firebase App ID', false, 'services.firebase.app_id'],
    ];

    /**
     * Config values used for empty settings. Read once: Realtime::configure()
     * later writes the resolved values back into the same keys.
     */
    protected static function fallback(string $key, array $field): ?string
    {
        self::$defaults ??= collect(static::FIELDS)
            ->map(fn ($f) => $f[2] ? config($f[2]) : null)
            ->put('realtime_driver', config('services.realtime.driver'))
            ->all();

        $value = self::$defaults[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    protected static function normalize(array $settings): array
    {
        $settings['realtime_driver'] = in_array($settings['realtime_driver'], self::DRIVERS, true)
            ? $settings['realtime_driver']
            : (self::$defaults['realtime_driver'] ?: 'none');

        return $settings;
    }

    public static function driver(): string
    {
        return self::get('realtime_driver', 'none');
    }
}
