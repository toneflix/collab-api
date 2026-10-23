<?php

namespace App\Traits;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For models that people can pay for (releases, track stems).
 */
trait Purchasable
{
    /**
     * Payments made for this model.
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactable');
    }

    /**
     * Whether the user has completed a payment of this kind for this model.
     */
    public function isPurchasedBy(?User $user, string $purpose): bool
    {
        if (! $user) {
            return false;
        }

        return $this->transactions()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('status', 'complete')
            ->exists();
    }
}
