<?php

namespace App\Services\Payment;

use App\Helpers\Providers;
use Illuminate\Validation\ValidationException;
use Yabacon\Paystack;
use Yabacon\Paystack\Exception\ApiException;

class PaystackGateway implements PaymentGateway, PayoutGateway
{
    protected function secret(): string
    {
        return (string) Providers::config('paystack_secret_key', env('PAYSTACK_SECRET_KEY'), true);
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
    protected function transfers(): Paystack
    {
        $client = $this->client();
        $client->useRoutes(['payouts' => PaystackTransfers::class]);

        return $client;
    }

    /**
     * Call Paystack, turning its refusals into validation errors.
     */
    protected function call(string $field, callable $request): object
    {
        try {
            return $request($this->transfers()->payouts);
        } catch (ApiException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([$field => 'We could not reach Paystack. Please try again.']);
        }
    }

    public function banks(string $currency): array
    {
        $response = $this->call('bank_code', fn ($api) => $api->banks(['currency' => $currency, 'perPage' => 100, 'use_cursor' => 'false']));

        return collect($response->data ?? [])
            ->filter(fn ($bank) => ($bank->active ?? true) && ! ($bank->is_deleted ?? false))
            ->map(fn ($bank) => ['name' => (string) $bank->name, 'code' => (string) $bank->code])
            ->unique('code')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function resolveAccount(string $accountNumber, string $bankCode): string
    {
        $response = $this->call('account_number', fn ($api) => $api->resolveAccount([
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]));

        return (string) ($response->data->account_name ?? '');
    }

    public function createRecipient(string $name, string $accountNumber, string $bankCode, string $currency): string
    {
        $response = $this->call('account_number', fn ($api) => $api->createRecipient([
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => $currency,
        ]));

        return (string) $response->data->recipient_code;
    }

    public function transfer(float $amount, string $currency, string $recipientCode, string $reference, string $reason): array
    {
        try {
            $response = $this->transfers()->payouts->initiate([
                'source' => 'balance',
                'amount' => (int) round($amount * 100), // kobo
                'currency' => $currency,
                'recipient' => $recipientCode,
                'reference' => $reference,
                'reason' => $reason,
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

    public function verifyTransfer(string $reference): array
    {
        try {
            $response = $this->transfers()->payouts->verify(['reference' => $reference]);
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
