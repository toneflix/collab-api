<?php

namespace App\Services\Payment;

use App\Helpers\Providers;
use Valorin\Random\Random;

/**
 * Payment and payout references, started with the prefix the payment relay routes Music's webhooks by.
 * Administrators may override the configured prefix in the app settings.
 */
class PaymentReference
{
    public static function prefix(): string
    {
        return (string) Providers::config('reference_prefix', config('services.relay.reference_prefix', 'tfm_'));
    }

    public static function payment(): string
    {
        return static::prefix() . Random::string(20, false, true, true, false);
    }

    public static function payout(): string
    {
        return static::prefix() . 'po_' . Random::string(24, true, false, true, false);
    }
}
