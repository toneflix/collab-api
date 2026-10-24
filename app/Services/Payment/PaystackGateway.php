<?php

namespace App\Services\Payment;

use App\Helpers\Providers;
use Illuminate\Validation\ValidationException;
use Yabacon\Paystack;
use Yabacon\Paystack\Exception\ApiException;

class PaystackGateway implements PaymentGateway
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
}
