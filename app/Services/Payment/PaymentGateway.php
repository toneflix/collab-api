<?php

namespace App\Services\Payment;

/**
 * The slice of a payment provider that checkout needs.
 * Amounts are in the main currency unit (e.g. naira), not kobo.
 */
interface PaymentGateway
{
    /**
     * The provider's key, as stored in `transactions.method`.
     */
    public function key(): string;

    /**
     * Whether the keys this provider needs to take payments are set.
     */
    public function acceptsPayments(): bool;

    /**
     * Start a payment and return what the client needs to complete it.
     * Throws a ValidationException when the payment can't be started.
     *
     * @return array{reference: string, access_code: ?string, authorization_url: ?string}
     */
    public function initialize(PaymentRequest $request): array;

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
