<?php

namespace App\Services\Account;

use App\Helpers\Providers;
use App\Models\User;

/**
 * Deactivating, deleting and bringing back an account at its owner's request.
 * Both hide the account and sign it out everywhere; a deleted account is
 * also erased once its grace period ends. Signing in again undoes either.
 */
class Deactivation
{
    /**
     * Days a deleted account waits before it is erased.
     */
    public static function graceDays(): int
    {
        return max(1, (int) Providers::config('account_deletion_days', 30));
    }

    /**
     * Hide the account and sign it out of every device. With `$delete`, the
     * account is erased after the grace period unless the owner signs in.
     */
    public static function deactivate(User $user, bool $delete = false): void
    {
        $user
            ->forceFill([
                'deactivated_at' => now(),
                'deletes_at' => $delete ? now()->addDays(static::graceDays()) : null,
            ])
            ->save();

        $user
            ->tokens()
            ->delete();
        $user
            ->deviceTokens()
            ->delete();
        $user
            ->pushSubscriptions()
            ->delete();
    }

    /**
     * Bring a deactivated or deleted account back. Returns whether it was off.
     */
    public static function reactivate(User $user): bool
    {
        if (! $user->isDeactivated()) {
            return false;
        }

        $user
            ->forceFill(['deactivated_at' => null, 'deletes_at' => null])
            ->save();

        return true;
    }
}
