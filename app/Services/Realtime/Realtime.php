<?php

namespace App\Services\Realtime;

use App\Broadcasting\FirebaseBroadcaster;
use App\Events\RealtimeMessage;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Sends realtime events and tells clients how to connect, using whichever
 * driver an admin picked and that driver's keys from `.env`.
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
     * Point Laravel's broadcasting at the driver an admin picked.
     */
    public static function configure(): void
    {
        config(['broadcasting.default' => static::CONNECTIONS[RealtimeSettings::driver()] ?? 'null']);

        // Drop drivers built for the old choice.
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
        $driver = RealtimeSettings::driver();
        $reverb = config('broadcasting.connections.reverb');
        $pusher = config('broadcasting.connections.pusher');
        $firebase = config('services.firebase');

        $config = match ($driver) {
            'reverb' => [
                'transport' => 'pusher',
                'pusher' => array_filter([
                    'key' => $reverb['key'] ?? null,
                    'cluster' => 'mt1',
                    'wsHost' => $reverb['options']['host'] ?? null,
                    'wsPort' => (int) (($reverb['options']['port'] ?? null) ?: 443),
                    'forceTLS' => (($reverb['options']['scheme'] ?? null) ?: 'https') === 'https',
                ], fn($v) => $v !== null && $v !== ''),
            ],
            'pusher' => [
                'transport' => 'pusher',
                'pusher' => ['key' => $pusher['key'] ?? null, 'cluster' => ($pusher['options']['cluster'] ?? null) ?: 'mt1'],
            ],
            // Ably speaks the Pusher protocol, so the Pusher client works with it.
            'ably' => [
                'transport' => 'pusher',
                'pusher' => [
                    'key' => str((string) config('broadcasting.connections.ably.key'))->before(':')->toString(),
                    'wsHost' => 'realtime-pusher.ably.io',
                    'wsPort' => 443,
                    'forceTLS' => true,
                ],
            ],
            'firebase' => [
                'transport' => 'firebase-database',
                'firebase-database' => array_filter([
                    'apiKey' => $firebase['api_key'] ?? null,
                    'projectId' => $firebase['project_id'] ?? null,
                    'appId' => $firebase['app_id'] ?? null,
                    'databaseURL' => $firebase['database_url'] ?? null,
                    'root' => config('broadcasting.connections.firebase.root'),
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
