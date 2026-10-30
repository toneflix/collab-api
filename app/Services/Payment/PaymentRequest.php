<?php

namespace App\Services\Payment;

/**
 * One payment to start with a gateway. The amount is in the main unit of `currency`.
 */
class PaymentRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $reference,
        /** Where the gateway sends the payer after paying off-site */
        public readonly string $returnUrl,
        /** Paid in the gateway's popup on the page rather than on its own checkout page */
        public readonly bool $inline = false,
        public readonly array $metadata = [],
    ) {}
}
