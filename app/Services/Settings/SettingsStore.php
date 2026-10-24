<?php

namespace App\Services\Settings;

use App\Models\Configuration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * A group of admin-editable settings kept in the `configurations` table.
 * Secret values are stored encrypted and never sent back to the browser.
 *
 * Subclasses list their settings in FIELDS as key => [title, secret, default, hint]
 * and name their cache key in CACHE_KEY.
 */
abstract class SettingsStore
{
    protected const CACHE_KEY = '';

    /**
     * @var array<string, array{0: string, 1: bool, 2: string|null, 3?: string}>
     */
    public const FIELDS = [];

    /**
     * Every setting, with secrets decrypted.
     *
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
     * Save settings. A secret sent as an empty value keeps its stored value,
     * so admins don't have to re-enter secrets to change something else.
     *
     * @param  array<string, mixed>  $values
     */
    public static function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(static::FIELDS[$key])) {
                continue;
            }

            [$title, $secret] = static::FIELDS[$key];

            if ($secret && blank($value)) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Configuration::query()->firstOrNew(['key' => $key])->forceFill([
                'key' => $key,
                'title' => $title,
                'type' => 'string',
                'secret' => $secret,
                'value' => blank($value) ? null : ($secret ? Crypt::encryptString((string) $value) : (string) $value),
            ])->save();
        }

        static::flush();
    }

    /**
     * Settings for an admin page: secrets only say whether they are set.
     *
     * @return array<string, array{title: string, secret: bool, hint: string, value: string|null, isSet: bool}>
     */
    public static function forAdmin(): array
    {
        $all = static::all();
        $out = [];

        foreach (static::FIELDS as $key => $field) {
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
        Cache::forget(static::CACHE_KEY);
        Cache::forget('configuration::build');
    }

    /**
     * The value used when a setting is empty.
     *
     * @param  array{0: string, 1: bool, 2: string|null, 3?: string}  $field
     */
    protected static function fallback(string $key, array $field): ?string
    {
        return $field[2];
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

            if ($field[1] && filled($value)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    $value = null;
                }
            }

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
