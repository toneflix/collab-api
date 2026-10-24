<?php

namespace App\Services\Payment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Pays creators across Africa through Flutterwave transfers, to banks and
 * mobile money. Transfers are paid from the app currency's balance and
 * converted by Flutterwave.
 */
class FlutterwaveGateway implements CollectsBankDetails, PayoutGateway
{
    protected const API = 'https://api.flutterwave.com/v3';

    /**
     * Countries paid through Flutterwave, as code => currency.
     */
    protected const COUNTRIES = [
        'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES', 'UG' => 'UGX', 'TZ' => 'TZS',
        'RW' => 'RWF', 'ZM' => 'ZMW', 'ZA' => 'ZAR', 'CM' => 'XAF', 'CI' => 'XOF', 'SN' => 'XOF',
    ];

    /**
     * Mobile money networks, as country => [code => name].
     */
    protected const NETWORKS = [
        'GH' => ['MTN' => 'MTN Mobile Money', 'VODAFONE' => 'Telecel Cash', 'AIRTELTIGO' => 'AirtelTigo Money'],
        'KE' => ['MPS' => 'M-Pesa'],
        'UG' => ['MPS' => 'MTN or Airtel Mobile Money'],
        'TZ' => ['MPS' => 'M-Pesa, Tigo Pesa or Airtel Money'],
        'RW' => ['MPS' => 'MTN or Airtel Mobile Money'],
        'ZM' => ['MPS' => 'MTN or Airtel Mobile Money'],
        'CM' => ['FMM' => 'MTN or Orange Mobile Money'],
        'CI' => ['FMM' => 'Orange, MTN or Moov Money'],
        'SN' => ['FMM' => 'Orange Money or Free Money'],
    ];

    /**
     * Countries whose bank accounts can be paid.
     */
    protected const BANK_COUNTRIES = ['NG', 'GH', 'KE', 'UG', 'TZ', 'ZA'];

    public function key(): string
    {
        return 'flutterwave';
    }

    public function countries(): array
    {
        return self::COUNTRIES;
    }

    public function currencyFor(string $country): string
    {
        return self::COUNTRIES[$country] ?? '';
    }

    public function methods(string $country): array
    {
        $methods = [];

        if (in_array($country, self::BANK_COUNTRIES, true)) {
            $methods[] = new PayoutMethod(
                'bank',
                'Bank account',
                'bank',
                // Flutterwave can only look up Nigerian account names
                $country === 'NG' ? PayoutMethod::LOOKUP_RESOLVE : PayoutMethod::LOOKUP_NONE,
                numberPattern: $country === 'NG' ? '^\d{10}$' : '^[A-Za-z0-9]{6,30}$',
            );
        }

        if (isset(self::NETWORKS[$country])) {
            $methods[] = new PayoutMethod('mobile_money', 'Mobile money', 'mobile_money', PayoutMethod::LOOKUP_NONE, 'Phone number with country code', '^\+?\d{9,15}$', 'Network');
        }

        return $methods;
    }

    public function banks(string $country, PayoutMethod $method): array
    {
        if ($method->key === 'mobile_money') {
            return collect(self::NETWORKS[$country] ?? [])
                ->map(fn ($name, $code) => ['name' => $name, 'code' => (string) $code])
                ->values()
                ->all();
        }

        $response = $this->send('bank_code', fn ($http) => $http->get("/banks/{$country}"));

        return collect($response->json('data', []))
            ->map(fn ($bank) => ['name' => (string) $bank['name'], 'code' => (string) $bank['code']])
            ->unique('code')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function lookup(string $country, PayoutMethod $method, string $bankCode, string $accountNumber, array $details = []): string
    {
        if ($method->lookup !== PayoutMethod::LOOKUP_RESOLVE) {
            return (string) ($details['account_name'] ?? '');
        }

        $response = $this->send('account_number', fn ($http) => $http->post('/accounts/resolve', [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode,
        ]));

        return (string) $response->json('data.account_name', '');
    }

    /**
     * Flutterwave takes the account details with each transfer, so the
     * "recipient" is the details themselves.
     */
    public function createRecipient(string $country, PayoutMethod $method, string $name, string $bankCode, string $accountNumber): string
    {
        return "{$bankCode}:{$accountNumber}";
    }

    public function rate(string $from, string $to): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        // Ask what 1000 of the destination currency costs in the source currency.
        $response = $this->send('payout', fn ($http) => $http->get('/transfers/rates', [
            'amount' => 1000,
            'source_currency' => $from,
            'destination_currency' => $to,
        ]));

        $source = (float) $response->json('data.source.amount', 0);
        $destination = (float) $response->json('data.destination.amount', 0);

        if ($source <= 0 || $destination <= 0) {
            throw ValidationException::withMessages(['payout' => "Flutterwave has no rate from {$from} to {$to}."]);
        }

        return $destination / $source;
    }

    public function transfer(PayoutTransfer $transfer): array
    {
        [$bank, $number] = array_pad(explode(':', $transfer->recipient, 2), 2, '');

        try {
            $response = $this->http()->post('/transfers', array_filter([
                'account_bank' => $bank,
                'account_number' => $number,
                'amount' => round($transfer->amount, 2),
                'currency' => $transfer->currency,
                'debit_currency' => $transfer->sourceCurrency !== $transfer->currency ? $transfer->sourceCurrency : null,
                'beneficiary_name' => $transfer->destination['account_name'] ?? null,
                'narration' => $transfer->reason,
                'reference' => $transfer->reference,
            ]));
        } catch (ConnectionException $e) {
            // The outcome is unknown, so leave it processing for the webhook or payouts:sync to settle.
            report($e);

            return ['status' => 'processing', 'transfer_code' => null, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        }

        if ($response->failed()) {
            throw ValidationException::withMessages(['payout' => $this->message($response)]);
        }

        return $this->result($response->json('data', []));
    }

    public function verifyTransfer(string $reference, ?string $transferCode = null): array
    {
        if (! $transferCode) {
            return ['status' => 'missing', 'transfer_code' => null, 'reason' => null, 'raw' => []];
        }

        try {
            $response = $this->http()->get("/transfers/{$transferCode}");
        } catch (ConnectionException $e) {
            return ['status' => 'processing', 'transfer_code' => $transferCode, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        }

        if ($response->status() === 404) {
            return ['status' => 'missing', 'transfer_code' => null, 'reason' => null, 'raw' => []];
        }

        if ($response->failed()) {
            return ['status' => 'processing', 'transfer_code' => $transferCode, 'reason' => null, 'raw' => ['message' => $this->message($response)]];
        }

        return $this->result($response->json('data', []));
    }

    /**
     * Whether a webhook came from Flutterwave: it sends back the secret hash set in its dashboard.
     */
    public function validWebhook(?string $hash): bool
    {
        $secret = (string) PayoutSettings::get('flutterwave_webhook_hash');

        return $hash !== null && $secret !== '' && hash_equals($secret, $hash);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    protected function result(array $data): array
    {
        $status = strtoupper((string) ($data['status'] ?? ''));

        return [
            'status' => match ($status) {
                'SUCCESSFUL' => 'paid',
                'FAILED' => 'failed',
                default => 'processing',
            },
            'transfer_code' => isset($data['id']) ? (string) $data['id'] : null,
            'reason' => $status === 'FAILED' ? ((string) ($data['complete_message'] ?? '') ?: 'The transfer failed.') : null,
            'raw' => $data,
        ];
    }

    protected function http(): PendingRequest
    {
        $secret = (string) PayoutSettings::get('flutterwave_secret_key');

        if ($secret === '') {
            throw ValidationException::withMessages(['payout' => 'Flutterwave payouts are not set up yet.']);
        }

        return Http::baseUrl(self::API)->withToken($secret)->acceptJson()->timeout(30);
    }

    /**
     * Call Flutterwave, turning failures into validation errors on `$field`.
     *
     * @param  callable(PendingRequest): Response  $request
     */
    protected function send(string $field, callable $request): Response
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException $e) {
            report($e);
            throw ValidationException::withMessages([$field => 'We could not reach Flutterwave. Please try again.']);
        }

        if ($response->failed()) {
            throw ValidationException::withMessages([$field => $this->message($response)]);
        }

        return $response;
    }

    protected function message(Response $response): string
    {
        return (string) ($response->json('message') ?: 'Flutterwave refused the request.');
    }
}
