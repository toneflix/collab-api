<?php

namespace App\Services;

use App\Helpers\Providers;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Account limits for creators. Premium accounts get the premium_* settings,
 * everyone else the free_* settings. A limit of 0 means no limit.
 */
class Limits
{
    public const KEYS = ['projects', 'collaborators', 'stem_size'];

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
}
