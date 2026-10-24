<?php

namespace App\Services\Payment;

/**
 * A payout provider where the creator types in their bank or mobile money
 * details and we register them with the provider.
 */
interface CollectsBankDetails
{
    /**
     * Banks, or mobile money networks, for a method in a country.
     *
     * @return array<int, array{name: string, code: string}>
     */
    public function banks(string $country, PayoutMethod $method): array;

    /**
     * Check an account and return the name on it. For methods without a lookup,
     * returns the name the creator gave.
     *
     * @param  array<string, string|null>  $details  account_name, account_type, document_type, document_number
     */
    public function lookup(string $country, PayoutMethod $method, string $bankCode, string $accountNumber, array $details = []): string;

    /**
     * Register the account to send transfers to, and return its recipient code.
     */
    public function createRecipient(string $country, PayoutMethod $method, string $name, string $bankCode, string $accountNumber): string;
}
