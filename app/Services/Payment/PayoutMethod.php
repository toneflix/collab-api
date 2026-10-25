<?php

namespace App\Services\Payment;

/**
 * A kind of account a provider can pay into, like a Nigerian bank account or
 * M-Pesa, and what is needed to check one before saving it.
 */
class PayoutMethod
{
    /** The provider tells us the name on the account */
    public const LOOKUP_RESOLVE = 'resolve';

    /** The creator gives their name and ID, which the provider checks against the bank */
    public const LOOKUP_VALIDATE = 'validate';

    /** Nothing to check against; the creator types the name on the account */
    public const LOOKUP_NONE = 'none';

    /** The provider collects the details on its own pages */
    public const LOOKUP_HOSTED = 'hosted';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $recipientType,
        public readonly string $lookup,
        public readonly string $numberLabel = 'Account number',
        public readonly string $numberPattern = '^\d{6,20}$',
        public readonly string $providerLabel = 'Bank',
    ) {}

    public function validNumber(string $number): bool
    {
        return (bool) preg_match('/' . $this->numberPattern . '/', $number);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'lookup' => $this->lookup,
            'numberLabel' => $this->numberLabel,
            'numberPattern' => $this->numberPattern,
            'providerLabel' => $this->providerLabel,
        ];
    }
}
