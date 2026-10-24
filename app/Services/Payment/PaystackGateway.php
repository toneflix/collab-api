<?php

namespace App\Services\Payment;

use App\Helpers\Providers;
use Illuminate\Validation\ValidationException;
use Toneflix\Paystack\Exception\ApiException;
use Toneflix\Paystack\Paystack;

class PaystackGateway implements CollectsBankDetails, PaymentGateway, PayoutGateway
{
    protected function secret(): string
    {
        return (string) config('services.paystack.secret_key');
    }

    protected function client(): Paystack
    {
        try {
            return new Paystack($this->secret());
        } catch (\InvalidArgumentException) {
            // No valid secret key in the settings yet
            throw ValidationException::withMessages(['payment' => 'Payments are not available right now.']);
        }
    }

    public function initialize(string $email, float $amount, string $reference, array $metadata = []): array
    {
        try {
            $response = $this->client()->transaction->initialize([
                'email' => $email,
                'amount' => (int) round($amount * 100), // kobo
                'reference' => $reference,
                'currency' => Providers::config('app_currency', 'NGN'),
                'metadata' => $metadata,
            ]);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['payment' => 'We could not reach Paystack. Please try again.']);
        }

        return [
            'reference' => $response->data->reference ?? $reference,
            'access_code' => $response->data->access_code ?? null,
            'authorization_url' => $response->data->authorization_url ?? null,
        ];
    }

    public function verify(string $reference): array
    {
        try {
            $response = $this->client()->transaction->verify(['reference' => $reference]);
        } catch (\Throwable $e) {
            // Leave the payment pending so a later check or the webhook can settle it.
            return ['paid' => false, 'final' => false, 'amount' => 0, 'currency' => null, 'raw' => ['message' => $e->getMessage()]];
        }

        $status = $response->data->status ?? null;

        return [
            'paid' => $status === 'success',
            // ongoing, pending, processing, queued and abandoned can still change
            'final' => in_array($status, ['success', 'failed', 'reversed'], true),
            'amount' => ((int) ($response->data->amount ?? 0)) / 100,
            'currency' => $response->data->currency ?? null,
            'raw' => json_decode(json_encode($response->data ?? []), true) ?? [],
        ];
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        return $signature !== null
            && $this->secret() !== ''
            && hash_equals(hash_hmac('sha512', $payload, $this->secret()), $signature);
    }

    /**
     * The client with the payout endpoints.
     */
    protected function transfers(): PaystackTransfers
    {
        return new PaystackTransfers($this->client());
    }

    /**
     * Call Paystack, turning its refusals into validation errors.
     */
    protected function call(string $field, callable $request): object
    {
        try {
            return $request($this->transfers());
        } catch (ApiException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([$field => 'We could not reach Paystack. Please try again.']);
        }
    }

    /**
     * Paystack only pays accounts in the country of the business, in its currency.
     */
    protected const COUNTRIES = ['NGN' => 'NG', 'GHS' => 'GH', 'KES' => 'KE', 'ZAR' => 'ZA'];

    public function key(): string
    {
        return 'paystack';
    }

    public function countries(): array
    {
        $currency = (string) Providers::config('app_currency', 'NGN');
        $country = self::COUNTRIES[$currency] ?? null;

        return $country ? [$country => $currency] : [];
    }

    public function currencyFor(string $country): string
    {
        return (string) (array_search($country, self::COUNTRIES, true) ?: '');
    }

    public function methods(string $country): array
    {
        $bank = fn (string $type, string $lookup, string $pattern = '^\d{6,20}$') => new PayoutMethod('bank', 'Bank account', $type, $lookup, numberPattern: $pattern);
        $mobile = fn (string $label) => new PayoutMethod('mobile_money', $label, 'mobile_money', PayoutMethod::LOOKUP_NONE, 'Phone number', '^\+?\d{9,15}$', 'Network');

        return match ($country) {
            'NG' => [$bank('nuban', PayoutMethod::LOOKUP_RESOLVE, '^\d{10}$')],
            'GH' => [$bank('ghipss', PayoutMethod::LOOKUP_RESOLVE), $mobile('Mobile money')],
            'KE' => [$bank('kepss', PayoutMethod::LOOKUP_NONE), $mobile('M-Pesa')],
            'ZA' => [$bank('basa', PayoutMethod::LOOKUP_VALIDATE)],
            default => [],
        };
    }

    public function rate(string $from, string $to): float
    {
        if (strtoupper($from) !== strtoupper($to)) {
            throw ValidationException::withMessages(['payout' => 'Paystack can only pay out in '.$from.'.']);
        }

        return 1.0;
    }

    public function banks(string $country, PayoutMethod $method): array
    {
        $currency = array_search($country, self::COUNTRIES, true) ?: 'NGN';
        $query = array_filter([
            'currency' => $currency,
            'type' => $method->key === 'mobile_money' ? 'mobile_money' : null,
            'enabled_for_verification' => $method->lookup === PayoutMethod::LOOKUP_VALIDATE ? 'true' : null,
            'perPage' => 100,
            'use_cursor' => 'false',
        ]);

        $response = $this->call('bank_code', fn ($api) => $api->banks($query));

        return collect($response->data ?? [])
            ->filter(fn ($bank) => ($bank->active ?? true) && ! ($bank->is_deleted ?? false))
            // Paystack can't pay into the central bank of Ghana
            ->reject(fn ($bank) => $country === 'GH' && str_contains(strtolower((string) $bank->name), 'bank of ghana'))
            ->map(fn ($bank) => ['name' => (string) $bank->name, 'code' => (string) $bank->code])
            ->unique('code')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function lookup(string $country, PayoutMethod $method, string $bankCode, string $accountNumber, array $details = []): string
    {
        if ($method->lookup === PayoutMethod::LOOKUP_RESOLVE) {
            $response = $this->call('account_number', fn ($api) => $api->resolveAccount([
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]));

            return (string) ($response->data->account_name ?? '');
        }

        if ($method->lookup === PayoutMethod::LOOKUP_VALIDATE) {
            $response = $this->call('account_number', fn ($api) => $api->validateAccount([
                'account_name' => $details['account_name'] ?? '',
                'account_number' => $accountNumber,
                'account_type' => $details['account_type'] ?? 'personal',
                'bank_code' => $bankCode,
                'country_code' => $country,
                'document_type' => $details['document_type'] ?? 'identityNumber',
                'document_number' => $details['document_number'] ?? '',
            ]));

            if (! ($response->data->verified ?? false)) {
                throw ValidationException::withMessages([
                    'account_number' => (string) ($response->data->verificationMessage ?? 'The bank couldn\'t confirm these details.'),
                ]);
            }
        }

        return (string) ($details['account_name'] ?? '');
    }

    public function createRecipient(string $country, PayoutMethod $method, string $name, string $bankCode, string $accountNumber): string
    {
        $response = $this->call('account_number', fn ($api) => $api->createRecipient([
            'type' => $method->recipientType,
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => array_search($country, self::COUNTRIES, true) ?: 'NGN',
        ]));

        return (string) $response->data->recipient_code;
    }

    public function transfer(PayoutTransfer $transfer): array
    {
        try {
            $response = $this->transfers()->initiate([
                'source' => 'balance',
                'amount' => (int) round($transfer->amount * 100), // kobo
                'currency' => $transfer->currency,
                'recipient' => $transfer->recipient,
                'reference' => $transfer->reference,
                'reason' => $transfer->reason,
            ]);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['payout' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // The outcome is unknown, so leave it processing for the webhook or payouts:sync to settle.
            report($e);

            return ['status' => 'processing', 'transfer_code' => null, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        }

        return $this->transferResult($response->data ?? null);
    }

    public function verifyTransfer(string $reference, ?string $transferCode = null): array
    {
        try {
            $response = $this->transfers()->verify(['reference' => $reference]);
        } catch (ApiException $e) {
            $missing = str_contains(strtolower($e->getMessage()), 'not found');

            return ['status' => $missing ? 'missing' : 'processing', 'transfer_code' => null, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['status' => 'processing', 'transfer_code' => null, 'reason' => null, 'raw' => ['message' => $e->getMessage()]];
        }

        return $this->transferResult($response->data ?? null);
    }

    /**
     * @return array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}
     */
    protected function transferResult(?object $data): array
    {
        $status = $data->status ?? null;

        return [
            'status' => match ($status) {
                'success' => 'paid',
                'failed', 'reversed', 'rejected', 'blocked', 'abandoned' => 'failed',
                // pending, received, queued, processing and otp
                default => 'processing',
            },
            'transfer_code' => $data->transfer_code ?? null,
            'reason' => match ($status) {
                'reversed' => 'The bank reversed this transfer.',
                'failed', 'rejected', 'blocked', 'abandoned' => 'The bank did not accept this transfer.',
                default => null,
            },
            'raw' => json_decode(json_encode($data ?? []), true) ?? [],
        ];
    }
}
