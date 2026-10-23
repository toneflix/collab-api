<?php

namespace App\Services\Realtime;

use App\Models\Configuration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Realtime settings, kept in the `configurations` table so admins can change
 * them. Secret values are encrypted. Anything left empty falls back to the
 * matching config value (and so `.env`), so a server can also be set up there.
 */
class RealtimeSettings
{
    public const DRIVERS = ['none', 'reverb', 'pusher', 'ably', 'firebase'];

    private const CACHE_KEY = 'realtime::settings';

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
     * All settings with secrets decrypted.
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        // Before migrations run there is nothing stored; don't cache that.
        if (! Cache::has(self::CACHE_KEY) && ! self::tableExists()) {
            return self::resolve([]);
        }

        return Cache::rememberForever(self::CACHE_KEY, fn () => self::resolve(
            Configuration::query()->toBase()->whereIn('key', array_keys(self::FIELDS))->pluck('value', 'key')->all()
        ));
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, string|null>
     */
    private static function resolve(array $stored): array
    {
        // Read the config fallbacks once: Realtime::configure() later
        // writes the resolved values back into the same keys.
        self::$defaults ??= collect(self::FIELDS)
            ->map(fn ($field) => $field[2] ? config($field[2]) : null)
            ->put('realtime_driver', config('services.realtime.driver'))
            ->all();

        $out = [];
        foreach (self::FIELDS as $key => [, $secret]) {
            $value = $stored[$key] ?? null;

            if ($secret && filled($value)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    $value = null;
                }
            }

            $out[$key] = filled($value) ? (string) $value : (filled(self::$defaults[$key] ?? null) ? (string) self::$defaults[$key] : null);
        }

        $out['realtime_driver'] = in_array($out['realtime_driver'], self::DRIVERS, true)
            ? $out['realtime_driver']
            : (self::$defaults['realtime_driver'] ?: 'none');

        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function driver(): string
    {
        return self::get('realtime_driver', 'none');
    }

    /**
     * Save settings. A secret sent as an empty value keeps its stored value,
     * so admins don't have to re-enter secrets to change something else.
     *
     * @param  array<string, mixed>  $values
     */
    public static function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::FIELDS[$key])) {
                continue;
            }

            [$title, $secret] = self::FIELDS[$key];

            if ($secret && blank($value)) {
                continue;
            }

            $config = Configuration::query()->firstOrNew(['key' => $key]);
            $config->forceFill([
                'key' => $key,
                'title' => $title,
                'type' => 'string',
                'secret' => $secret,
                'value' => blank($value) ? null : ($secret ? Crypt::encryptString((string) $value) : (string) $value),
            ])->save();
        }

        self::flush();
    }

    /**
     * Settings for the admin page. Secrets are never sent back; only whether
     * they are set.
     *
     * @return array<string, mixed>
     */
    public static function forAdmin(): array
    {
        $all = self::all();
        $out = [];

        foreach (self::FIELDS as $key => $field) {
            [$title, $secret] = $field;
            $out[$key] = [
                'title' => $title,
                'secret' => $secret,
                'hint' => $field[3] ?? '',
                'value' => $secret ? null : $all[$key],
                'isSet' => filled($all[$key]),
            ];
        }

        return $out;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('configuration::build');
    }

    private static function tableExists(): bool
    {
        try {
            return Schema::hasTable('configurations');
        } catch (\Throwable) {
            return false;
        }
    }
}
