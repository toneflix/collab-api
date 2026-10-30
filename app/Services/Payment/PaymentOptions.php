<?php

namespace App\Services\Payment;

/**
 * How a payer pays: the currency they are charged in, the rate from the app
 * currency, and the providers they can choose from (first is the default).
 */
class PaymentOptions
{
    /**
     * @param  array<int, string>  $providers
     */
    public function __construct(
        public readonly ?string $country,
        public readonly string $currency,
        public readonly array $providers,
        public readonly float $rate = 1.0,
        public readonly ?int $regionId = null,
        public readonly ?string $regionName = null,
    ) {}

    /**
     * An amount in the app currency, in the charge currency. Rounded up so the
     * payer never pays less than the price.
     */
    public function charge(float $amount): float
    {
        return ceil(round($amount * $this->rate * 100, 4)) / 100;
    }

    /**
     * The provider to use: the one asked for if it is allowed here, else the default.
     */
    public function provider(?string $asked): ?string
    {
        if ($asked !== null && $asked !== '') {
            return in_array($asked, $this->providers, true) ? $asked : null;
        }

        return $this->providers[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'providers' => $this->providers,
            'region' => $this->regionId ? ['id' => $this->regionId, 'name' => $this->regionName] : null,
        ];
    }
}
