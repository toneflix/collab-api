<?php

namespace App\Services;

use App\Helpers\Providers;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Account limits for creators. Premium accounts get the premium_* settings,
 * everyone else the free_* settings. A limit of 0 means no limit.
 */
class Limits
{
    public const KEYS = ['projects', 'collaborators', 'stem_size', 'storage'];

    public function __construct(public ?User $user) {}

    public static function for(?User $user): self
    {
        return new self($user);
    }

    public function tier(): string
    {
        return $this->user?->is_premium ? 'premium' : 'free';
    }

    /**
     * The limit for a key on this account. 0 means no limit.
     */
    public function get(string $key): int
    {
        return max(0, (int) Providers::config("{$this->tier()}_max_{$key}", 0));
    }

    /**
     * All limits for a tier, for showing on the Premium page.
     *
     * @return array<string, int>
     */
    public static function forTier(string $tier): array
    {
        return collect(self::KEYS)
            ->mapWithKeys(fn ($key) => [str($key)->camel()->toString() => max(0, (int) Providers::config("{$tier}_max_{$key}", 0))])
            ->all();
    }

    /**
     * Stop when the account already has as many as its limit allows.
     */
    public function ensureBelow(string $key, int $current, string $field, string $message): void
    {
        $limit = $this->get($key);

        if ($limit > 0 && $current >= $limit) {
            throw ValidationException::withMessages([
                $field => __($message, ['limit' => $limit]).($this->tier() === 'free' ? ' Upgrade to Premium for more.' : ''),
            ]);
        }
    }

    /**
     * A validation rule for the largest stem upload, in kilobytes, or null for no limit.
     */
    public function stemSizeRule(): ?string
    {
        $mb = $this->get('stem_size');

        return $mb > 0 ? 'max:'.($mb * 1024) : null;
    }

    /**
     * Bytes this account's own sounds take up.
     */
    public function storageUsed(): int
    {
        return $this->user ? (int) Sound::query()->where('user_id', $this->user->id)->sum('size') : 0;
    }

    /**
     * Storage use and limit, in bytes. A limit of 0 means no limit.
     *
     * @return array{used: int, limit: int}
     */
    public function storage(): array
    {
        return ['used' => $this->storageUsed(), 'limit' => $this->get('storage') * 1024 * 1024];
    }

    /**
     * Stop when a new file of this many bytes wouldn't fit in the account's storage.
     */
    public function ensureRoomFor(int $bytes, string $field = 'file'): void
    {
        ['used' => $used, 'limit' => $limit] = $this->storage();

        if ($limit > 0 && $used + $bytes > $limit) {
            $free = max(0, $limit - $used);
            throw ValidationException::withMessages([
                $field => __('This needs :need, but only :free of your :limit is free.', [
                    'need' => self::readable($bytes),
                    'free' => self::readable($free),
                    'limit' => self::readable($limit),
                ]).($this->tier() === 'free' ? ' Upgrade to Premium for more.' : ' Remove some sounds to make room.'),
            ]);
        }
    }

    public static function readable(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => round($bytes / 1024 ** 3, 1).' GB',
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 1).' MB',
            default => max(1, (int) round($bytes / 1024)).' KB',
        };
    }
}
