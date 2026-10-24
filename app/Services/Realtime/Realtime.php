<?php

namespace App\Services\Realtime;

use App\Broadcasting\FirebaseBroadcaster;
use App\Events\RealtimeMessage;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Sends realtime events and tells clients how to connect, using whichever
 * driver an admin picked in the realtime settings.
 */
class Realtime
{
    /**
     * Laravel broadcast connection for each driver.
     */
    public const CONNECTIONS = [
        'none' => 'null',
        'reverb' => 'reverb',
        'pusher' => 'pusher',
        'ably' => 'ably',
        'firebase' => 'firebase',
    ];

    /**
     * Header a client sends with every request so it can recognise, and skip,
     * the events its own changes caused.
     */
    public const ORIGIN_HEADER = 'X-Realtime-Origin';

    public static function enabled(): bool
    {
        return RealtimeSettings::driver() !== 'none';
    }

    /**
     * Broadcast an event. Realtime is a nice-to-have, so a driver that is
     * down or misconfigured is reported and never fails the request.
     *
     * @param  Channel|array<int, Channel|null>|null  $channels
     * @param  array<string, mixed>  $data
     */
    public static function send(Channel|array|null $channels, string $event, array $data = [], ?Authenticatable $by = null): bool
    {
        $channels = array_values(array_filter(is_array($channels) ? $channels : [$channels]));

        if (! $channels || ! static::enabled()) {
            return false;
        }

        $by ??= request()->user();

        $data['origin'] = static::origin();
        $data['by'] = $by ? ['id' => $by->getAuthIdentifier(), 'name' => $by->fullname ?? $by->name ?? null] : null;

        try {
            broadcast(new RealtimeMessage($channels, $event, $data));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The sending tab's id, from the origin header.
     */
    public static function origin(): ?string
    {
        $origin = request()?->header(static::ORIGIN_HEADER);

        return is_string($origin) && preg_match('/^[\w-]{1,64}$/', $origin) ? $origin : null;
    }

    /**
     * Point Laravel's broadcasting (and a local Reverb server) at the stored
     * settings.
     */
    public static function configure(): void
    {
        $s = RealtimeSettings::all();
        $driver = $s['realtime_driver'];

        $reverbTls = ($s['reverb_scheme'] ?: 'https') === 'https';

        config([
            'broadcasting.default' => static::CONNECTIONS[$driver] ?? 'null',

            'broadcasting.connections.reverb.key' => $s['reverb_key'],
            'broadcasting.connections.reverb.secret' => $s['reverb_secret'],
            'broadcasting.connections.reverb.app_id' => $s['reverb_app_id'],
            'broadcasting.connections.reverb.options.host' => $s['reverb_host'],
            'broadcasting.connections.reverb.options.port' => (int) ($s['reverb_port'] ?: 443),
            'broadcasting.connections.reverb.options.scheme' => $s['reverb_scheme'] ?: 'https',
            'broadcasting.connections.reverb.options.useTLS' => $reverbTls,

            'reverb.apps.apps.0.key' => $s['reverb_key'],
            'reverb.apps.apps.0.secret' => $s['reverb_secret'],
            'reverb.apps.apps.0.app_id' => $s['reverb_app_id'],
            'reverb.apps.apps.0.options.host' => $s['reverb_host'],
            'reverb.apps.apps.0.options.port' => (int) ($s['reverb_port'] ?: 443),
            'reverb.apps.apps.0.options.scheme' => $s['reverb_scheme'] ?: 'https',
            'reverb.apps.apps.0.options.useTLS' => $reverbTls,

            'broadcasting.connections.pusher.key' => $s['pusher_key'],
            'broadcasting.connections.pusher.secret' => $s['pusher_secret'],
            'broadcasting.connections.pusher.app_id' => $s['pusher_app_id'],
            'broadcasting.connections.pusher.options.cluster' => $s['pusher_cluster'] ?: 'mt1',
            'broadcasting.connections.pusher.options.host' => 'api-'.($s['pusher_cluster'] ?: 'mt1').'.pusher.com',

            'broadcasting.connections.ably.key' => $s['ably_key'],

            'broadcasting.connections.firebase' => [
                'driver' => 'firebase',
                'credentials' => $s['firebase_credentials'],
                'database_url' => $s['firebase_database_url'],
                'root' => 'arkstack/channels',
                'grants_root' => 'arkstack/grants',
                'grant_ttl' => 3600,
            ],
        ]);

        // Drop drivers built from the old settings.
        if (app()->resolved(BroadcastManager::class)) {
            app(BroadcastManager::class)->forgetDrivers();
        }
    }

    /**
     * What a browser needs to connect, in the shape of @arkstack/realtime's
     * config. Never includes secrets.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(): array
    {
        $s = RealtimeSettings::all();
        $driver = $s['realtime_driver'];

        $config = match ($driver) {
            'reverb' => [
                'transport' => 'pusher',
                'pusher' => array_filter([
                    'key' => $s['reverb_key'],
                    'cluster' => 'mt1',
                    'wsHost' => $s['reverb_host'],
                    'wsPort' => (int) ($s['reverb_port'] ?: 443),
                    'forceTLS' => ($s['reverb_scheme'] ?: 'https') === 'https',
                ], fn ($v) => $v !== null && $v !== ''),
            ],
            'pusher' => [
                'transport' => 'pusher',
                'pusher' => ['key' => $s['pusher_key'], 'cluster' => $s['pusher_cluster'] ?: 'mt1'],
            ],
            // Ably speaks the Pusher protocol, so the Pusher client works with it.
            'ably' => [
                'transport' => 'pusher',
                'pusher' => [
                    'key' => str($s['ably_key'] ?? '')->before(':')->toString(),
                    'wsHost' => 'realtime-pusher.ably.io',
                    'wsPort' => 443,
                    'forceTLS' => true,
                ],
            ],
            'firebase' => [
                'transport' => 'firebase-database',
                'firebase-database' => array_filter([
                    'apiKey' => $s['firebase_api_key'],
                    'projectId' => $s['firebase_project_id'],
                    'appId' => $s['firebase_app_id'],
                    'databaseURL' => $s['firebase_database_url'],
                    'root' => 'arkstack/channels',
                ]),
            ],
            default => [],
        };

        return ['driver' => $driver, 'authPath' => 'realtime/auth', 'originHeader' => static::ORIGIN_HEADER] + $config;
    }

    /**
     * The Firebase broadcaster, when Firebase is the driver.
     */
    public static function firebase(): ?FirebaseBroadcaster
    {
        if (RealtimeSettings::driver() !== 'firebase') {
            return null;
        }

        $driver = app(BroadcastManager::class)->connection('firebase');

        return $driver instanceof FirebaseBroadcaster ? $driver : null;
    }
}
