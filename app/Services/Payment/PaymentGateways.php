<?php

namespace App\Services\Payment;

use Illuminate\Validation\ValidationException;

/**
 * The payment providers, and which of them admins have switched on.
 */
class PaymentGateways
{
    /**
     * @param  array<int, PaymentGateway>  $gateways  In order of preference
     */
    public function __construct(protected array $gateways) {}

    /**
     * @return array<int, PaymentGateway>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Providers that are switched on and have their keys set.
     *
     * @return array<int, PaymentGateway>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->gateways,
            fn(PaymentGateway $g) => PayoutSettings::paymentsEnabled($g->key()) && $g->acceptsPayments(),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(fn(PaymentGateway $g) => $g->key(), $this->gateways);
    }

    public function get(string $key): PaymentGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->key() === $key) {
                return $gateway;
            }
        }

        throw ValidationException::withMessages(['provider' => "Unknown payment provider {$key}."]);
    }
}
