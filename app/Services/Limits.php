<?php

namespace App\Services;

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Account limits for creators, read from the plan the account is on.
 * A limit of 0 means no limit.
 */
class Limits
{
    public function __construct(public ?User $user) {}

    public static function for(?User $user): self
    {
        return new self($user);
    }

    public function plan(): Plan
    {
        return $this->user?->current_plan ?? Plan::default();
    }

    /**
     * The limit for a feature on this account. 0 means no limit.
     */
    public function get(Feature $feature): int
    {
        return (int) $this->plan()->feature($feature);
    }

    /**
     * Stop when the account already has as many as its limit allows.
     */
    public function ensureBelow(Feature $feature, int $current, string $field, string $message): void
    {
        $limit = $this->get($feature);

        if ($limit > 0 && $current >= $limit) {
            throw ValidationException::withMessages([
                $field => __($message, ['limit' => $limit]) . ($this->canUpgrade() ? ' Upgrade your plan for more.' : ''),
            ]);
        }
    }

    /**
     * Stop when the account's plan doesn't include a feature.
     */
    public function ensureAllowed(Feature $feature, string $field, ?string $message = null): void
    {
        if (! $this->plan()->feature($feature)) {
            throw ValidationException::withMessages([
                $field => $message ?? __(':feature is not included in your plan.', ['feature' => $feature->title()]),
            ]);
        }
    }

    /**
     * Stop a new price when the plan doesn't allow selling. A price that is already
     * set can stay, so a creator who changes plans can still edit their release.
     */
    public function ensureCanCharge(Feature $feature, mixed $price, float $current, string $field): void
    {
        if (is_numeric($price) && (float) $price > 0 && abs((float) $price - $current) > 0.001) {
            $this->ensureAllowed($feature, $field);
        }
    }

    /**
     * A validation rule for the largest stem upload, in kilobytes, or null for no limit.
     */
    public function stemSizeRule(): ?string
    {
        $mb = $this->get(Feature::MAX_STEM_SIZE);

        return $mb > 0 ? 'max:' . ($mb * 1024) : null;
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
        return ['used' => $this->storageUsed(), 'limit' => $this->get(Feature::MAX_STORAGE) * 1024 * 1024];
    }

    /**
     * Stop when a new file of this many bytes wouldn't fit in the account's storage.
     */
    public function ensureRoomFor(int $bytes, string $field = 'file'): void
    {
        ['used' => $used, 'limit' => $limit] = $this->storage();

        if ($limit > 0 && $used + $bytes > $limit) {
            throw ValidationException::withMessages([
                $field => __('This needs :need, but only :free of your :limit is free.', [
                    'need' => self::readable($bytes),
                    'free' => self::readable(max(0, $limit - $used)),
                    'limit' => self::readable($limit),
                ]) . ($this->canUpgrade() ? ' Upgrade your plan for more.' : ' Remove some sounds to make room.'),
            ]);
        }
    }

    public static function readable(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => round($bytes / 1024 ** 3, 1) . ' GB',
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 1) . ' MB',
            default => max(1, (int) round($bytes / 1024)) . ' KB',
        };
    }

    protected function canUpgrade(): bool
    {
        return Plan::onOffer()
            ->where('price', '>', $this->plan()->price)
            ->exists();
    }
}
