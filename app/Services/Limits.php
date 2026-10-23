<?php

namespace App\Services;

use App\Enums\Feature;
use App\Models\Plan;
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
                $field => __($message, ['limit' => $limit]).($this->canUpgrade() ? ' Upgrade your plan for more.' : ''),
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

        return $mb > 0 ? 'max:'.($mb * 1024) : null;
    }

    protected function canUpgrade(): bool
    {
        return Plan::active()->where('price', '>', $this->plan()->price)->exists();
    }
}
