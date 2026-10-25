<?php

namespace App\Services\Payment;

use Illuminate\Validation\ValidationException;

/**
 * The payout providers that are switched on, and which one pays each country.
 * Earlier providers win where they overlap, so Paystack pays its own country,
 * Flutterwave the rest of Africa and Stripe everywhere else it reaches.
 */
class PayoutProviders
{
    /**
     * @param  array<int, PayoutGateway>  $gateways  In order of preference
     */
    public function __construct(protected array $gateways) {}

    /**
     * @return array<int, PayoutGateway>
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->gateways, fn(PayoutGateway $g) => PayoutSettings::enabled($g->key())));
    }

    public function get(string $key): PayoutGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->key() === $key) {
                return $gateway;
            }
        }

        throw ValidationException::withMessages(['provider' => "Unknown payout provider {$key}."]);
    }

    /**
     * The provider that pays creators in a country.
     */
    public function forCountry(string $country): PayoutGateway
    {
        foreach ($this->enabled() as $gateway) {
            if (isset($gateway->countries()[$country])) {
                return $gateway;
            }
        }

        throw ValidationException::withMessages(['country' => 'Withdrawals to this country are not available yet.']);
    }

    /**
     * Every country creators can be paid in, with its provider, currency and account types.
     *
     * @return array<int, array{code: string, name: string, provider: string, currency: string, methods: array<int, array<string, string>>}>
     */
    public function countries(): array
    {
        $out = [];

        foreach ($this->enabled() as $gateway) {
            foreach (array_keys($gateway->countries()) as $code) {
                $out[$code] ??= [
                    'code' => $code,
                    'name' => \Locale::getDisplayRegion("-{$code}", 'en') ?: $code,
                    'provider' => $gateway->key(),
                    'currency' => $gateway->currencyFor($code),
                    'methods' => array_map(fn(PayoutMethod $m) => $m->toArray(), $gateway->methods($code)),
                ];
            }
        }

        return collect($out)->sortBy('name')->values()->all();
    }

    /**
     * A kind of account the provider for a country can pay into.
     */
    public function method(string $country, string $key): PayoutMethod
    {
        foreach ($this->forCountry($country)->methods($country) as $method) {
            if ($method->key === $key) {
                return $method;
            }
        }

        throw ValidationException::withMessages(['method' => 'Choose how you want to be paid.']);
    }
}
