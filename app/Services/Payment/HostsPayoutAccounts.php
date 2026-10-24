<?php

namespace App\Services\Payment;

use App\Models\User;

/**
 * A payout provider that collects the creator's details on its own pages,
 * like Stripe Connect onboarding.
 */
interface HostsPayoutAccounts
{
    /**
     * Create the creator's account with the provider if needed, and return a link
     * to its onboarding.
     *
     * @return array{account_id: string, url: string}
     */
    public function onboard(User $user, string $country, ?string $accountId, string $returnUrl): array;

    /**
     * Whether the account has finished onboarding and can receive transfers.
     */
    public function ready(string $accountId): bool;
}
