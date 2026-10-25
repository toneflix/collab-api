<?php

namespace App\Services\Sounds;

use App\Models\FreesoundConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A small client for the Freesound API. Only CC0 sounds are ever offered or
 * downloaded, so nothing taken from Freesound needs a credit or limits sales.
 *
 * @see https://freesound.org/docs/api/
 */
class Freesound
{
    public const CC0_FILTER = 'license:"Creative Commons 0"';

    public const FIELDS = 'id,name,tags,username,license,duration,previews,url,type,filesize';

    public static function base(): string
    {
        return rtrim((string) config('services.freesound.base_url', 'https://freesound.org/apiv2'), '/');
    }

    /**
     * Whether a license link is CC0 (public domain dedication).
     */
    public static function isCc0(?string $license): bool
    {
        return str_contains(strtolower((string) $license), 'publicdomain/zero');
    }

    protected static function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(20)->retry(2, 500, throw: false);
    }

    /**
     * Search CC0 sounds with the app's API key.
     *
     * @return array{count: int, results: array<int, array<string, mixed>>}
     */
    public static function search(string $query, int $page = 1, int $pageSize = 20, ?float $maxDuration = null): array
    {
        $filter = self::CC0_FILTER . ($maxDuration ? ' duration:[0 TO ' . $maxDuration . ']' : '');

        $response = self::http()->get(self::base() . '/search/text/', [
            'query' => $query,
            'filter' => $filter,
            'fields' => self::FIELDS,
            'page' => max(1, $page),
            'page_size' => max(1, min(150, $pageSize)),
            'token' => config('services.freesound.client_secret'),
        ]);

        // A page past the end is a 404 on Freesound.
        if ($response->status() === 404) {
            return ['count' => 0, 'results' => []];
        }

        self::check($response->status(), 'search');

        $data = $response->json();

        return [
            'count' => (int) ($data['count'] ?? 0),
            // The filter should only return CC0, but check each one anyway.
            'results' => collect($data['results'] ?? [])
                ->filter(fn($s) => self::isCc0($s['license'] ?? null))
                ->map(fn($s) => self::normalize($s))
                ->values()
                ->all(),
        ];
    }

    /**
     * One sound's details.
     *
     * @return array<string, mixed>
     */
    public static function sound(string|int $id): array
    {
        $response = self::http()->get(self::base() . '/sounds/' . urlencode((string) $id) . '/', [
            'fields' => self::FIELDS,
            'token' => config('services.freesound.client_secret'),
        ]);

        if ($response->status() === 404) {
            throw ValidationException::withMessages(['id' => 'That sound is no longer on Freesound.']);
        }

        self::check($response->status(), 'sound');

        return self::normalize($response->json());
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function normalize(array $s): array
    {
        $tags = array_values(array_filter((array) ($s['tags'] ?? []), 'is_string'));

        return [
            'id' => (string) ($s['id'] ?? ''),
            'name' => (string) ($s['name'] ?? 'Untitled'),
            'tags' => $tags,
            'author' => $s['username'] ?? null,
            'license' => $s['license'] ?? null,
            'cc0' => self::isCc0($s['license'] ?? null),
            'duration' => round((float) ($s['duration'] ?? 0), 3),
            'type' => $s['type'] ?? null,
            'size' => (int) ($s['filesize'] ?? 0),
            'bpm' => self::bpmFrom($s['name'] ?? '', $tags),
            'previewUrl' => $s['previews']['preview-hq-mp3'] ?? $s['previews']['preview-lq-mp3'] ?? null,
            'sourceUrl' => $s['url'] ?? null,
        ];
    }

    /**
     * Freesound has no tempo field we can rely on, but loops are usually
     * tagged or named with it, like "120bpm".
     *
     * @param  array<int, string>  $tags
     */
    public static function bpmFrom(string $name, array $tags): ?float
    {
        foreach ([...$tags, $name] as $text) {
            if (preg_match('/(?:^|[^\d])(\d{2,3}(?:\.\d+)?)\s*-?\s*bpm\b|\bbpm\s*-?\s*(\d{2,3})(?!\d)/i', (string) $text, $m)) {
                $bpm = (float) ($m[1] !== '' ? $m[1] : $m[2]);
                if ($bpm >= 40 && $bpm <= 250) {
                    return $bpm;
                }
            }
        }

        return null;
    }

    /**
     * Where to send someone to connect their Freesound account.
     */
    public static function authorizeUrl(string $state): string
    {
        return self::base() . '/oauth2/authorize/?' . http_build_query([
            'client_id' => config('services.freesound.client_id'),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /**
     * Swap the code from the callback for tokens and save the connection.
     */
    public static function connect(User $user, string $code): FreesoundConnection
    {
        $tokens = self::token(['grant_type' => 'authorization_code', 'code' => $code]);

        $me = self::http()->withToken($tokens['access_token'])->get(self::base() . '/me/');

        return FreesoundConnection::query()->updateOrCreate(['user_id' => $user->id], [
            'username' => $me->successful() ? ($me->json('username') ?: null) : null,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 86400)),
        ]);
    }

    /**
     * A working access token for a connection, refreshed when it has expired.
     */
    public static function accessToken(FreesoundConnection $connection): string
    {
        if (! $connection->expired()) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            throw new RuntimeException('The Freesound connection has expired. Connect Freesound again.');
        }

        $tokens = self::token(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);

        $connection->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 86400)),
        ])->save();

        return $connection->access_token;
    }

    /**
     * @param  array<string, string>  $grant
     * @return array<string, mixed>
     */
    protected static function token(array $grant): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::base() . '/oauth2/access_token/', [
            'client_id' => config('services.freesound.client_id'),
            'client_secret' => config('services.freesound.client_secret'),
            ...$grant,
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Freesound didn\'t accept the sign-in. Try connecting again.');
        }

        return $response->json();
    }

    /**
     * Download a sound to a temporary file. With a connection the original
     * file is downloaded; without one, the high quality MP3 preview.
     *
     * @param  array<string, mixed>  $sound  A normalized sound
     * @return array{path: string, ext: string, mime: string, quality: string, size: int}
     */
    public static function download(array $sound, ?FreesoundConnection $connection = null): array
    {
        if (! self::isCc0($sound['license'] ?? null)) {
            throw ValidationException::withMessages(['id' => 'Only CC0 sounds can be imported.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'fs-');

        if ($connection) {
            $response = Http::withToken(self::accessToken($connection))
                ->timeout(120)
                ->withOptions(['sink' => $path])
                ->get(self::base() . '/sounds/' . urlencode((string) $sound['id']) . '/download/');
            $quality = 'original';
            $ext = strtolower((string) ($sound['type'] ?: 'wav'));
        } else {
            if (empty($sound['previewUrl'])) {
                throw ValidationException::withMessages(['id' => 'That sound has no preview to download.']);
            }
            $response = Http::timeout(60)->withOptions(['sink' => $path])->get($sound['previewUrl']);
            $quality = 'preview';
            $ext = 'mp3';
        }

        if (! $response->successful()) {
            @unlink($path);
            self::check($response->status(), 'download');
        }

        $ext = $ext === 'aif' ? 'aiff' : $ext;
        $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');

        return ['path' => $path, 'ext' => $ext, 'mime' => $mime, 'quality' => $quality, 'size' => (int) filesize($path)];
    }

    protected static function check(int $status, string $what): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = match (true) {
            $status === 401 || $status === 403 => 'Freesound refused the request. Check the API key, or connect Freesound again.',
            $status === 429 => 'Freesound is limiting requests right now. Try again in a minute.',
            default => "Freesound couldn't be reached ({$what}, {$status}). Try again later.",
        };

        throw new RuntimeException($message, $status);
    }
}
