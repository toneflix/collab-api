<?php

namespace App\Services\Settings;

use App\Models\Configuration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * A group of admin-editable settings kept in the `configurations` table.
 *
 * Subclasses list their settings in FIELDS as key => [title, default, hint],
 * and the API keys they read from `.env` in KEYS as
 * group => [ENV_NAME => [title, config path, required]].
 */
abstract class SettingsStore
{
    protected const CACHE_KEY = '';

    /**
     * @var array<string, array{0: string, 1: string|null, 2?: string}>
     */
    public const FIELDS = [];

    /**
     * @var array<string, array<string, array{0: string, 1: string, 2?: bool}>>
     */
    public const KEYS = [];

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        // Before migrations run there is nothing stored; don't cache that.
        if (! Cache::has(static::CACHE_KEY) && ! static::tableExists()) {
            return static::resolve([]);
        }

        return Cache::rememberForever(static::CACHE_KEY, fn () => static::resolve(
            Configuration::query()->toBase()->whereIn('key', array_keys(static::FIELDS))->pluck('value', 'key')->all()
        ));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all()[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(static::FIELDS[$key])) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Configuration::query()->firstOrNew(['key' => $key])->forceFill([
                'key' => $key,
                'title' => static::FIELDS[$key][0],
                'type' => 'string',
                'secret' => false,
                'value' => blank($value) ? null : (string) $value,
            ])->save();
        }

        static::flush();
    }

    /**
     * @return array<string, array{title: string, hint: string, value: string|null}>
     */
    public static function forAdmin(): array
    {
        $all = static::all();
        $out = [];

        foreach (static::FIELDS as $key => $field) {
            $out[$key] = [
                'title' => $field[0],
                'hint' => $field[2] ?? '',
                'value' => $all[$key],
            ];
        }

        return $out;
    }

    /**
     * The `.env` keys of each group and whether they are set. Values are never included.
     *
     * @return array<string, array<int, array{env: string, title: string, required: bool, isSet: bool}>>
     */
    public static function keys(): array
    {
        return collect(static::KEYS)->map(fn (array $keys) => collect($keys)->map(fn (array $key, string $env) => [
            'env' => $env,
            'title' => $key[0],
            'required' => $key[2] ?? true,
            'isSet' => filled(config($key[1])),
        ])->values()->all())->all();
    }

    /**
     * Titles of the required keys in a group that `.env` leaves empty.
     *
     * @return array<int, string>
     */
    public static function missing(string $group): array
    {
        return collect(static::keys()[$group] ?? [])
            ->filter(fn (array $key) => $key['required'] && ! $key['isSet'])
            ->map(fn (array $key) => "{$key['title']} ({$key['env']})")
            ->values()
            ->all();
    }

    public static function configured(string $group): bool
    {
        return isset(static::KEYS[$group]) && static::missing($group) === [];
    }

    public static function flush(): void
    {
        Cache::forget(static::CACHE_KEY);
        Cache::forget('configuration::build');
    }

    /**
     * The value used when a setting is empty.
     *
     * @param  array{0: string, 1: string|null, 2?: string}  $field
     */
    protected static function fallback(string $key, array $field): ?string
    {
        return $field[1];
    }

    /**
     * Last adjustments to the resolved settings.
     *
     * @param  array<string, string|null>  $settings
     * @return array<string, string|null>
     */
    protected static function normalize(array $settings): array
    {
        return $settings;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, string|null>
     */
    protected static function resolve(array $stored): array
    {
        $out = [];
        foreach (static::FIELDS as $key => $field) {
            $value = $stored[$key] ?? null;
            $fallback = static::fallback($key, $field);

            $out[$key] = filled($value) ? (string) $value : (filled($fallback) ? (string) $fallback : null);
        }

        return static::normalize($out);
    }

    protected static function tableExists(string $table = 'configurations'): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
