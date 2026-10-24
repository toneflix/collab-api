<?php

namespace App\Services\Payment;

/**
 * The slice of a payment provider that sends money to creators' bank accounts.
 * Amounts are in the main currency unit (e.g. naira), not kobo.
 * Methods throw a ValidationException when the provider refuses the request.
 */
interface PayoutGateway
{
    /**
     * Banks that can receive transfers.
     *
     * @return array<int, array{name: string, code: string}>
     */
    public function banks(string $currency): array;

    /**
     * The name on a bank account.
     */
    public function resolveAccount(string $accountNumber, string $bankCode): string;

    /**
     * Register a bank account to send transfers to, and return its recipient code.
     */
    public function createRecipient(string $name, string $accountNumber, string $bankCode, string $currency): string;

    /**
     * Send money to a recipient. `status` is paid, processing or failed; processing
     * also covers requests whose outcome is unknown (e.g. the provider timed out).
     *
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    public function transfer(float $amount, string $currency, string $recipientCode, string $reference, string $reason): array;

    /**
     * Ask the provider what happened to a transfer. `status` is paid, processing,
     * failed, or missing when the provider never received it.
     *
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    public function verifyTransfer(string $reference): array;
}
