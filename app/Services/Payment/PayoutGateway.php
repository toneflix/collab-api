<?php

namespace App\Services\Payment;

/**
 * A provider that sends creators their earnings. Amounts are in the main
 * currency unit (e.g. naira), not kobo. Methods throw a ValidationException
 * when the provider refuses a request.
 */
interface PayoutGateway
{
    /**
     * The provider's key, like `paystack`, stored on payout accounts and payouts.
     */
    public function key(): string;

    /**
     * Countries the provider can pay into, as country code => currency.
     *
     * @return array<string, string>
     */
    public function countries(): array;

    /**
     * The currency transfers to a country are sent in.
     */
    public function currencyFor(string $country): string;

    /**
     * Kinds of accounts it can pay into in a country.
     *
     * @return array<int, PayoutMethod>
     */
    public function methods(string $country): array;

    /**
     * How much of `$to` one unit of `$from` buys right now.
     */
    public function rate(string $from, string $to): float;

    /**
     * Send money. `status` is paid, processing or failed; processing also covers
     * requests whose outcome is unknown (e.g. the provider timed out).
     *
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    public function transfer(PayoutTransfer $transfer): array;

    /**
     * Ask what happened to a transfer. `status` is paid, processing, failed, or
     * missing when the provider never received it.
     *
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    public function verifyTransfer(string $reference, ?string $transferCode = null): array;
}
