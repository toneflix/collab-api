<?php

namespace App\Services\Sounds;

use App\Models\Sound;
use App\Services\Settings\SettingsStore;
use Illuminate\Support\Facades\Cache;

/**
 * Settings for the sound library and Freesound, kept in the `configurations`
 * table so admins can change them. The Freesound secret is encrypted and never
 * sent back to the browser.
 */
class SoundSettings extends SettingsStore
{
    protected const CACHE_KEY = 'sounds::settings';

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

    public static function flush(): void
    {
        parent::flush();
        Cache::forget('sounds::library-size');
    }
}
