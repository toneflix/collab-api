<?php

namespace App\Services\Sounds;

use App\Models\Configuration;
use App\Models\Sound;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Settings for the sound library and Freesound, kept in the `configurations`
 * table so admins can change them. The Freesound secret is encrypted and never
 * sent back to the browser.
 */
class SoundSettings
{
    private const CACHE_KEY = 'sounds::settings';

    /**
     * Every setting: key => [title, secret, default, hint].
     *
     * @var array<string, array{0: string, 1: bool, 2: string|null, 3?: string}>
     */
    public const FIELDS = [
        'sounds_library_enabled' => ['Sound Library', false, '0', 'Show the shared sound library in the studio. It stays hidden while it has no sounds.'],
        'freesound_enabled' => ['Freesound', false, '0', 'Let people search Freesound and import CC0 sounds into My sounds.'],
        'freesound_client_id' => ['Freesound Client ID', false, null, 'From your Freesound API app.'],
        'freesound_client_secret' => ['Freesound Client Secret / API Key', true, null, 'From your Freesound API app. Stored encrypted.'],
        'freesound_sync_queries' => ['Library Sync Searches', false, null, 'One search per line. Start a line with a category and a colon, like "Drums: drum loop".'],
        'freesound_sync_limit' => ['Sounds per Search', false, '25', 'How many sounds each sync search adds, at most.'],
        'freesound_sync_max_duration' => ['Longest Sound (seconds)', false, '30', 'Longer sounds are skipped by the sync.'],
        'freesound_library_account' => ['Library Download Account', false, null, 'The admin whose connected Freesound account downloads original files for the library.'],
        'freesound_last_sync' => ['Last Library Sync', false, null, ''],
    ];

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
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
        $out = [];
        foreach (self::FIELDS as $key => [, $secret, $default]) {
            $value = $stored[$key] ?? null;

            if ($secret && filled($value)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    $value = null;
                }
            }

            $out[$key] = filled($value) ? (string) $value : $default;
        }

        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function libraryEnabled(): bool
    {
        return filter_var(self::get('sounds_library_enabled'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Whether Freesound search and import are on and have keys.
     */
    public static function freesoundEnabled(): bool
    {
        return filter_var(self::get('freesound_enabled'), FILTER_VALIDATE_BOOL) && self::freesoundConfigured();
    }

    public static function freesoundConfigured(): bool
    {
        return filled(self::get('freesound_client_id')) && filled(self::get('freesound_client_secret'));
    }

    /**
     * The sync searches: each has a category and a query.
     *
     * @return array<int, array{category: string, query: string}>
     */
    public static function syncQueries(): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) self::get('freesound_sync_queries')))
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->map(function ($line) {
                [$category, $query] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [$line, $line];

                return ['category' => $category ?: $query, 'query' => $query ?: $category];
            })
            ->filter(fn ($q) => $q['query'] !== '')
            ->take(30)
            ->values()
            ->all();
    }

    /**
     * What the studio shows. The library is hidden while it's off or empty.
     *
     * @return array{library: bool, freesound: bool}
     */
    public static function clientConfig(): array
    {
        return [
            'library' => self::libraryEnabled() && self::librarySize() > 0,
            'freesound' => self::freesoundEnabled(),
        ];
    }

    /**
     * Active sounds in the shared library.
     */
    public static function librarySize(): int
    {
        if (! self::tableExists('sounds')) {
            return 0;
        }

        return Cache::remember('sounds::library-size', 300, fn () => Sound::query()->library()->active()->count());
    }

    /**
     * Save settings. A secret sent as an empty value keeps its stored value.
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

        self::flush();
    }

    /**
     * Settings for the admin page. The secret is never sent back, only whether it's set.
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
        Cache::forget('sounds::library-size');
        Cache::forget('configuration::build');
    }

    private static function tableExists(string $table = 'configurations'): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
