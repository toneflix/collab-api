<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Factory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Broadcasts over Firebase Realtime Database, in the layout the
 * `firebase-database` transport of @arkstack/realtime reads:
 *
 * - each event is written to `{root}/{channel}/{key}` as `{event, payload, at}`
 *   and indexed under `{root}-index/{key}`, so old events can be pruned;
 * - authorizing a channel writes a time-limited grant to
 *   `{grants}/{uid}/{channel}` and returns a custom token to sign in with.
 *   The database rules check the grant before allowing a read.
 */
class FirebaseBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    protected ?Database $database = null;

    protected ?Auth $firebaseAuth = null;

    /**
     * @param  array{credentials?: string|null, database_url?: string|null, root?: string, grants_root?: string, grant_ttl?: int}  $config
     */
    public function __construct(protected array $config = []) {}

    public function root(): string
    {
        return $this->config['root'] ?? 'arkstack/channels';
    }

    public function grantsRoot(): string
    {
        return $this->config['grants_root'] ?? 'arkstack/grants';
    }

    /**
     * The database key for a channel name: what `encodeURIComponent` gives,
     * with dots encoded too, since keys can't hold them.
     */
    public static function channelKey(string $channel): string
    {
        $key = strtr(rawurlencode($channel), [
            '%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')',
        ]);

        return str_replace('.', '%2E', $key);
    }

    /**
     * The Firebase uid for a user.
     */
    public static function uid(mixed $user): string
    {
        return 'user-' . $user->getAuthIdentifier();
    }

    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) || ! $this->retrieveUser($request, $channelName)) {
            throw new AccessDeniedHttpException;
        }

        return $this->verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * Grant the user read access to the channel and return the custom token
     * the client signs in with.
     */
    public function validAuthenticationResponse($request, $result)
    {
        $uid = static::uid($this->retrieveUser($request, $this->normalizeChannelName($request->channel_name)));
        $expiresAt = (int) round((microtime(true) + ($this->config['grant_ttl'] ?? 3600)) * 1000);

        $this->write([
            $this->grantsRoot() . '/' . $uid . '/' . static::channelKey($request->channel_name) => $expiresAt,
        ]);

        return [
            'token' => $this->firebaseAuth()->createCustomToken($uid)->toString(),
            'expires_at' => $expiresAt,
        ];
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        unset($payload['socket']);

        $updates = [];
        $at = ['.sv' => 'timestamp'];

        foreach ($this->formatChannels($channels) as $channel) {
            $key = Str::lower((string) Str::ulid());
            $channelKey = static::channelKey($channel);

            $updates["{$this->root()}/{$channelKey}/{$key}"] = compact('event', 'payload', 'at');
            $updates["{$this->root()}-index/{$key}"] = ['channel' => $channelKey, 'at' => $at];
        }

        if (! $updates) {
            return;
        }

        try {
            $this->write($updates);
        } catch (\Throwable $e) {
            throw new BroadcastException('Firebase broadcast failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete events older than `$maxAge` seconds, at most `$limit` at a time.
     *
     * @return int The number of events deleted.
     */
    public function prune(int $maxAge, int $limit = 500): int
    {
        $cutoff = (int) round((microtime(true) - $maxAge) * 1000);

        $old = $this->database()
            ->getReference($this->root() . '-index')
            ->orderByChild('at')
            ->endAt($cutoff)
            ->limitToFirst($limit)
            ->getValue() ?: [];

        $updates = [];
        foreach ($old as $key => $entry) {
            if (! empty($entry['channel'])) {
                $updates["{$this->root()}/{$entry['channel']}/{$key}"] = null;
            }
            $updates["{$this->root()}-index/{$key}"] = null;
        }

        if ($updates) {
            $this->write($updates);
        }

        return count($old);
    }

    /**
     * Apply a multi-path update at the database root.
     *
     * @param  array<string, mixed>  $updates
     */
    protected function write(array $updates): void
    {
        $this->database()->getReference()->update($updates);
    }

    protected function factory(): Factory
    {
        $credentials = $this->config['credentials'] ?? null;
        $url = $this->config['database_url'] ?? null;

        if (blank($credentials) || blank($url)) {
            throw new BroadcastException('Firebase realtime needs a service account and a database URL.');
        }

        $decoded = json_decode((string) $credentials, true);

        return (new Factory)
            ->withServiceAccount(is_array($decoded) ? $decoded : (string) $credentials)
            ->withDatabaseUri((string) $url);
    }

    public function database(): Database
    {
        return $this->database ??= $this->factory()->createDatabase();
    }

    public function firebaseAuth(): Auth
    {
        return $this->firebaseAuth ??= $this->factory()->createAuth();
    }

    /**
     * Use these clients instead of building them (tests).
     */
    public function using(Database $database, ?Auth $auth = null): static
    {
        $this->database = $database;
        $this->firebaseAuth = $auth;

        return $this;
    }
}
