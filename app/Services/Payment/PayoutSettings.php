<?php

namespace App\Services\Payment;

use App\Helpers\Providers;
use App\Services\Settings\SettingsStore;

/**
 * Which payout providers are on, and their keys.
 */
class PayoutSettings extends SettingsStore
{
    protected const CACHE_KEY = 'payouts::settings';

    public const FIELDS = [
        'paystack_payouts_enabled' => ['Paystack Payouts', false, '1', 'Pay creators in the country of your Paystack account, using the Paystack keys in the general settings.'],

        'flutterwave_payouts_enabled' => ['Flutterwave Payouts', false, '0', 'Pay creators in other African countries through Flutterwave.'],
        'flutterwave_secret_key' => ['Flutterwave Secret Key', true, null, 'From Settings > API keys in your Flutterwave dashboard.'],
        'flutterwave_webhook_hash' => ['Flutterwave Webhook Secret Hash', true, null, 'The secret hash you set under Settings > Webhooks.'],

        'stripe_payouts_enabled' => ['Stripe Connect Payouts', false, '0', 'Pay creators in Europe, North America and other Stripe countries. They finish Stripe\'s onboarding first.'],
        'stripe_connect_secret_key' => ['Stripe Secret Key', true, null, 'Leave empty to use the Stripe key from the general settings.'],
        'stripe_webhook_secret' => ['Stripe Webhook Signing Secret', true, null, 'From the webhook endpoint you add for transfer events.'],
        'stripe_platform_country' => ['Stripe Account Country', false, 'US', 'The two-letter country of your Stripe account.'],
        'stripe_currency' => ['Stripe Balance Currency', false, 'USD', 'The currency transfers are sent in, usually your Stripe account\'s currency.'],
        'stripe_exchange_rate' => ['Stripe Exchange Rate', false, null, 'How much of the Stripe currency one unit of the app currency is worth. Not needed when both are the same.'],
    ];

    public static function enabled(string $provider): bool
    {
        return filter_var(static::get("{$provider}_payouts_enabled"), FILTER_VALIDATE_BOOL);
    }

    public static function stripeSecret(): ?string
    {
        $key = static::get('stripe_connect_secret_key') ?: Providers::config('stripe_secret_key', env('STRIPE_SECRET_KEY'), true);

        return filled($key) ? (string) $key : null;
    }
}
