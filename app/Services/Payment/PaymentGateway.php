<?php

namespace App\Services\Payment;

/**
 * The small slice of a payment provider that checkout needs.
 * Amounts are in the main currency unit (e.g. naira), not kobo.
 */
interface PaymentGateway
{
    /**
     * Start a payment and return what the client needs to complete it.
     * Throws a ValidationException when the payment can't be started.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{reference: string, access_code: ?string, authorization_url: ?string}
     */
    public function initialize(string $email, float $amount, string $reference, array $metadata = []): array;

    /**
     * Ask the provider what happened to a payment. `final` is false while the
     * outcome is still unknown (still in progress, or the provider couldn't be reached).
     *
     * @return array{paid: bool, final: bool, amount: float, currency: ?string, raw: array<string, mixed>}
     */
    public function verify(string $reference): array;

    /**
     * Whether a webhook body really came from the provider.
     */
    public function validWebhook(string $payload, ?string $signature): bool;
}
