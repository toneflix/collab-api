<?php

namespace App\Services\Payment;

/**
 * One transfer to send: the amount the creator receives, in their currency,
 * and the app currency it is paid from.
 */
class PayoutTransfer
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $sourceCurrency,
        public readonly string $recipient,
        public readonly string $reference,
        public readonly string $reason,
        public readonly string $country = '',
        public readonly string $method = 'bank',
        /** Bank details for providers that take them with each transfer */
        public readonly array $destination = [],
    ) {}
}
