<?php

namespace App\Services\Payment;

use App\Services\Settings\SettingsStore;

/**
 * Which providers take payments and pay creators. Their keys come from `.env`.
 */
class PayoutSettings extends SettingsStore
{
    protected const CACHE_KEY = 'payouts::settings';

    public const FIELDS = [
        'paystack_payments_enabled' => ['Paystack Payments', '1', 'Take payments with Paystack.'],

        'paystack_payouts_enabled' => ['Paystack Payouts', '1', 'Pay creators in the country of your Paystack account.'],

        'flutterwave_payouts_enabled' => ['Flutterwave Payouts', '0', 'Pay creators in other African countries through Flutterwave.'],

        'stripe_payouts_enabled' => ['Stripe Connect Payouts', '0', 'Pay creators in Europe, North America and other Stripe countries. They finish Stripe\'s onboarding first.'],
        'stripe_platform_country' => ['Stripe Account Country', 'US', 'The two-letter country of your Stripe account.'],
        'stripe_currency' => ['Stripe Balance Currency', 'USD', 'The currency transfers are sent in, usually your Stripe account\'s currency.'],
        'stripe_exchange_rate' => ['Stripe Exchange Rate', null, 'How much of the Stripe currency one unit of the app currency is worth. Not needed when both are the same.'],
    ];

    public const KEYS = [
        'paystack' => [
            'PAYSTACK_SECRET_KEY' => ['Paystack Secret Key', 'services.paystack.secret_key'],
            'PAYSTACK_PUBLIC_KEY' => ['Paystack Public Key', 'services.paystack.public_key'],
        ],
        'flutterwave' => [
            'FLUTTERWAVE_SECRET_KEY' => ['Flutterwave Secret Key', 'services.flutterwave.secret_key'],
            'FLUTTERWAVE_WEBHOOK_HASH' => ['Flutterwave Webhook Secret Hash', 'services.flutterwave.webhook_hash'],
        ],
        'stripe' => [
            'STRIPE_SECRET_KEY' => ['Stripe Secret Key', 'services.stripe.secret_key'],
            'STRIPE_CONNECT_SECRET_KEY' => ['Stripe Connect Secret Key', 'services.stripe.connect_secret_key', false],
            'STRIPE_WEBHOOK_SECRET' => ['Stripe Webhook Signing Secret', 'services.stripe.webhook_secret'],
        ],
    ];

    public static function enabled(string $provider): bool
    {
        return filter_var(static::get("{$provider}_payouts_enabled"), FILTER_VALIDATE_BOOL);
    }

    public static function paymentsEnabled(string $provider): bool
    {
        return filter_var(static::get("{$provider}_payments_enabled"), FILTER_VALIDATE_BOOL);
    }

    public static function stripeSecret(): ?string
    {
        $key = config('services.stripe.connect_secret_key') ?: config('services.stripe.secret_key');

        return filled($key) ? (string) $key : null;
    }
}
