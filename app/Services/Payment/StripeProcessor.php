<?php

namespace App\Services\Payment;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Models\TempUser;
use App\Models\User;
use App\Services\CustomObject;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

final class StripeProcessor implements PaymentInterface
{
    protected Request $request;

    protected StripeClient $stripe;

    protected User|TempUser|null $user;

    /**
     * StripeProcessor constructor.
     */
    public function __construct(Request $request, User|TempUser|null $user = null)
    {
        $this->request = $request;
        $this->user = $user;
        $this->stripe = new StripeClient(
            (string) Providers::config('stripe_secret_key', env('STRIPE_SECRET_KEY'), true)
        );
    }

    /**
     * Generate a payment intent
     *
     * @param  int|float  $amount  The amount to charge, in the major currency unit (e.g. dollars)
     * @param  callable  $successCallback  The callback function to call when the intent is created
     * @param  callable  $errorCallback  The callback function to call when an error occurs
     * @param  bool  $respond  Wether to return a response or not.
     * @return CustomObject
     */
    public function intent(
        int|float $amount,
        ?callable $successCallback = null,
        ?callable $errorCallback = null,
        ?bool $respond = true
    ) {
        $params = [
            // Stripe expects the amount in the smallest currency unit (e.g. cents)
            'amount' => (int) round($amount * 100),
            'currency' => strtolower(Providers::config('app_currency', 'USD')),
            'automatic_payment_methods' => ['enabled' => true],
        ];

        if ($this->request->pm_id) {
            $params['payment_method'] = $this->request->pm_id;
        }

        if ($this->user?->stripe_id ?? null) {
            $params['customer'] = $this->user->stripe_id;
        }

        try {
            $intent = $this->format($this->stripe->paymentIntents->create($params)->toArray());
            $intent['reference'] = $intent['id'];
            $intent['status_code'] = HttpStatus::OK;
            $intent['message'] = 'Transaction initialized';

            if ($successCallback) {
                $response = $successCallback($intent['id'], $intent, $amount, $intent['message'], HttpStatus::OK);
                if ($respond) {
                    return $response;
                }
            }

            return $intent;
        } catch (ApiErrorException $th) {
            return $this->error($th, $errorCallback);
        }
    }

    /**
     * Verify a payment intent
     *
     * @param  callable  $errorCallback  The callback function to call when an error occurs
     * @param  bool  $respond  Wether to return a response or not.
     * @return CustomObject
     */
    public function verify(
        string $reference,
        ?callable $successCallback = null,
        ?callable $errorCallback = null,
        ?bool $respond = true,
    ) {
        try {
            $ref = $this->format($this->stripe->paymentIntents->retrieve($reference)->toArray());
            $ref['reference'] = $ref['id'];
            $ref['status_code'] = HttpStatus::OK;
            $ref['message'] = 'Transaction verified';

            if ($successCallback) {
                $response = $successCallback($reference, $ref, $ref['message'], HttpStatus::OK);
                if ($respond) {
                    return $response;
                }
            }

            return $ref;
        } catch (ApiErrorException $th) {
            return $this->error($th, $errorCallback);
        }
    }

    /**
     * Detach a saved payment method from the customer.
     *
     * @param  string  $authorization_code  The Stripe payment method id
     * @return CustomObject
     */
    public function deauthorize(
        string $authorization_code,
        ?callable $callback = null,
        ?callable $error_callback = null,
        ?bool $respond = true
    ) {
        try {
            $method = new CustomObject($this->stripe->paymentMethods->detach($authorization_code)->toArray());

            if ($callback) {
                $response = $callback($method, 'Payment method removed', HttpStatus::OK);
                if ($respond) {
                    return $response;
                }
            }

            return $method;
        } catch (ApiErrorException $th) {
            return $this->error($th, $error_callback);
        }
    }

    /**
     * Pay out to the user's connected Stripe account.
     *
     * @param  ?string  $reason
     * @return CustomObject
     */
    public function transfer(
        int|float $amount,
        ?string $reason = null,
        ?callable $successCallback = null,
        ?callable $errorCallback = null,
        ?bool $respond = true
    ) {
        $account = $this->user?->data['stripe_account_id'] ?? null;

        if (! $account) {
            $error = new CustomObject([
                'error' => HttpStatus::UNPROCESSABLE_ENTITY->value,
                'status_code' => HttpStatus::UNPROCESSABLE_ENTITY,
                'message' => 'This user has no connected Stripe account.',
            ]);

            return $errorCallback ? $errorCallback($error['message'], HttpStatus::UNPROCESSABLE_ENTITY, $error) : $error;
        }

        try {
            $tranx = new CustomObject($this->stripe->transfers->create([
                'amount' => (int) round($amount * 100),
                'currency' => strtolower(Providers::config('app_currency', 'USD')),
                'destination' => $account,
                'description' => $reason,
            ])->toArray());

            if ($successCallback) {
                $response = $successCallback($tranx, 'Transfer Completed', HttpStatus::OK);
                if ($respond) {
                    return $response;
                }
            }

            return $tranx;
        } catch (ApiErrorException $th) {
            return $this->error($th, $errorCallback);
        }
    }

    /**
     * Normalize a Stripe payment intent so it matches the shape other processors return.
     */
    protected function format(array $intent): CustomObject
    {
        if (($intent['status'] ?? null) === 'succeeded') {
            $intent['status'] = 'success';
            $intent['payment_approved'] = true;
        } else {
            $intent['payment_approved'] = false;
        }

        return new CustomObject($intent);
    }

    protected function error(ApiErrorException $th, ?callable $errorCallback = null): mixed
    {
        $code = HttpStatus::tryFrom((int) $th->getHttpStatus()) ?? HttpStatus::BAD_REQUEST;

        $error = new CustomObject([
            'code' => $th->getStripeCode(),
            'error' => $code->value,
            'status_code' => $code,
            'message' => $th->getMessage(),
        ]);

        if ($errorCallback) {
            return $errorCallback($th->getMessage(), $code, $error);
        }

        return $error;
    }
}
