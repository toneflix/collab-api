<?php

namespace App\Services\Payment;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Stripe\Event;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Pays creators through Stripe Connect. Each creator gets an Express account and
 * gives their bank details to Stripe; we transfer to that account and Stripe
 * pays it out to their bank.
 */
class StripeConnectGateway implements HostsPayoutAccounts, PayoutGateway
{
    /**
     * Countries Stripe Express accounts can be paid in, as code => currency.
     */
    protected const COUNTRIES = [
        'US' => 'USD', 'CA' => 'CAD', 'GB' => 'GBP', 'IE' => 'EUR', 'DE' => 'EUR', 'FR' => 'EUR',
        'NL' => 'EUR', 'BE' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'PT' => 'EUR', 'AT' => 'EUR',
        'FI' => 'EUR', 'LU' => 'EUR', 'GR' => 'EUR', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK',
        'DK' => 'DKK', 'PL' => 'PLN', 'AU' => 'AUD', 'NZ' => 'NZD', 'SG' => 'SGD', 'HK' => 'HKD',
        'JP' => 'JPY', 'AE' => 'AED', 'MX' => 'MXN',
    ];

    /**
     * Currencies without minor units.
     */
    protected const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'PYG', 'XAF', 'XOF', 'UGX', 'RWF'];

    public function key(): string
    {
        return 'stripe';
    }

    public function countries(): array
    {
        return self::COUNTRIES;
    }

    public function currencyFor(string $country): string
    {
        return $this->currency();
    }

    public function methods(string $country): array
    {
        return [new PayoutMethod('hosted', 'Bank account through Stripe', 'express', PayoutMethod::LOOKUP_HOSTED)];
    }

    /**
     * Transfers go out in the Stripe balance currency, so that is what this
     * converts into, using the rate admins set.
     */
    public function rate(string $from, string $to): float
    {
        if (strtoupper($from) === $this->currency()) {
            return 1.0;
        }

        $rate = (float) PayoutSettings::get('stripe_exchange_rate');

        if ($rate <= 0) {
            throw ValidationException::withMessages(['payout' => 'Set the Stripe exchange rate from '.strtoupper($from).' to '.$this->currency().' first.']);
        }

        return $rate;
    }

    /**
     * The currency transfers are sent in.
     */
    public function currency(): string
    {
        return strtoupper((string) PayoutSettings::get('stripe_currency', 'USD'));
    }

    public function onboard(User $user, string $country, ?string $accountId, string $returnUrl): array
    {
        return $this->call('account', function (StripeClient $stripe) use ($user, $country, $accountId, $returnUrl) {
            if (! $accountId) {
                $crossBorder = $country !== strtoupper((string) PayoutSettings::get('stripe_platform_country', 'US'));

                $account = $stripe->accounts->create(array_filter([
                    'type' => 'express',
                    'country' => $country,
                    'email' => $user->email,
                    'capabilities' => ['transfers' => ['requested' => true]],
                    // Accounts outside the platform's country can only receive transfers
                    'tos_acceptance' => $crossBorder ? ['service_agreement' => 'recipient'] : null,
                    'metadata' => ['user_id' => (string) $user->id],
                ]));

                $accountId = $account->id;
            }

            $link = $stripe->accountLinks->create([
                'account' => $accountId,
                'type' => 'account_onboarding',
                'refresh_url' => $returnUrl,
                'return_url' => $returnUrl,
            ]);

            return ['account_id' => $accountId, 'url' => $link->url];
        });
    }

    public function ready(string $accountId): bool
    {
        $account = $this->call('account', fn (StripeClient $stripe) => $stripe->accounts->retrieve($accountId));

        return ($account->capabilities->transfers ?? null) === 'active';
    }

    public function transfer(PayoutTransfer $transfer): array
    {
        try {
            $result = $this->client()->transfers->create([
                'amount' => $this->minor($transfer->amount, $transfer->currency),
                'currency' => strtolower($transfer->currency),
                'destination' => $transfer->recipient,
                'transfer_group' => $transfer->reference,
                'description' => $transfer->reason,
                'metadata' => ['reference' => $transfer->reference],
            ], ['idempotency_key' => $transfer->reference]);
        } catch (ApiConnectionException $e) {
            // The outcome is unknown, so leave it processing for payouts:sync to settle.
            report($e);

            return ['status' => 'processing', 'transfer_code' => null, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages(['payout' => $e->getMessage()]);
        }

        return $this->result($result->toArray());
    }

    public function verifyTransfer(string $reference, ?string $transferCode = null): array
    {
        try {
            $transfer = $transferCode
                ? $this->client()->transfers->retrieve($transferCode)
                : ($this->client()->transfers->all(['transfer_group' => $reference, 'limit' => 1])->data[0] ?? null);
        } catch (InvalidRequestException) {
            $transfer = null;
        } catch (ApiErrorException $e) {
            return ['status' => 'processing', 'transfer_code' => $transferCode, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        }

        if (! $transfer) {
            return ['status' => 'missing', 'transfer_code' => null, 'reason' => null, 'raw' => []];
        }

        return $this->result($transfer->toArray());
    }

    /**
     * Read a webhook event, or null when its signature doesn't match.
     */
    public function webhookEvent(string $payload, ?string $signature): ?Event
    {
        $secret = (string) PayoutSettings::get('stripe_webhook_secret');

        if ($secret === '' || ! $signature) {
            return null;
        }

        try {
            return Webhook::constructEvent($payload, $signature, $secret);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A transfer lands in the creator's Stripe account straight away; a reversal takes it back.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    protected function result(array $data): array
    {
        $reversed = ($data['reversed'] ?? false) || (($data['amount_reversed'] ?? 0) > 0);

        return [
            'status' => $reversed ? 'failed' : 'paid',
            'transfer_code' => $data['id'] ?? null,
            'reason' => $reversed ? 'The transfer was reversed.' : null,
            'raw' => $data,
        ];
    }

    protected function minor(float $amount, string $currency): int
    {
        return (int) round(in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? $amount : $amount * 100);
    }

    protected function client(): StripeClient
    {
        $secret = PayoutSettings::stripeSecret();

        if (! $secret) {
            throw ValidationException::withMessages(['payout' => 'Stripe payouts are not set up yet.']);
        }

        return new StripeClient($secret);
    }

    /**
     * Call Stripe, turning its errors into validation errors on `$field`.
     *
     * @template T
     *
     * @param  callable(StripeClient): T  $request
     * @return T
     */
    protected function call(string $field, callable $request): mixed
    {
        try {
            return $request($this->client());
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
