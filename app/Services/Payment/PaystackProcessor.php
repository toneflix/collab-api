<?php

namespace App\Services\Payment;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Models\TempUser;
use App\Models\User;
use App\Services\CustomObject;
use Illuminate\Http\Request;
use Toneflix\Paystack\Exception\ApiException;
use Toneflix\Paystack\MetadataBuilder;
use Toneflix\Paystack\Paystack;
use Valorin\Random\Random;

class PaystackProcessor implements PaymentInterface
{
    protected $request;

    protected $user;

    /**
     * PaystackProcessor constructor.
     */
    public function __construct(Request $request, User|TempUser|null $user = null)
    {
        $this->request = $request;
        $this->user = $user;
    }

    /**
     * Initialize a paystack transaction.
     *
     * @param  int|float  $amount  The amount to initialize the transaction with
     * @param  callable  $successCallback  The callback function to call when the transaction is initialized
     * @param  callable  $errorCallback  The callback function to call when an error occurs
     * @param  bool  $respond  Wether to return a response or not.
     * @return array|object|CustomObject
     */
    public function intent(
        int|float $amount,
        ?callable $callback = null,
        ?callable $error_callback = null,
        ?bool $respond = true
    ) {
        $tranx = null;
        $user = $this->user;
        $code = HttpStatus::BAD_REQUEST;
        $due = $amount;
        $msg = 'Transaction Failed';

        $reference = Providers::config('reference_prefix', 'TRX-') . Random::string(20, ! 1, ! 0, ! 0, ! 1);
        $real_due = round($due * 100, 2);

        $response = new \stdClass;

        // Initialize paystack transaction
        try {
            $paystack = new Paystack((string) config('services.paystack.secret_key'));

            // Dont initialize paystack for inline transaction
            if ($this->request->inline) {
                $tranx = [
                    'data' => ['reference' => $reference],
                ];
            } else {
                $builder = new MetadataBuilder;
                $builder->withCustomField('Name', $user->fullname);
                $builder->withCustomField('Phone Number', $user->phone);

                if ($this->request->get('redirect_cancel')) {
                    $builder->withCancelAction($this->request->get('redirect_cancel'));
                }

                if ($this->request->get('recurring')) {
                    $builder->withCustomFilters([
                        'recurring' => true,
                    ]);
                }

                $tranx = $paystack->transaction->initialize([
                    'amount' => $real_due,       // in kobo
                    'email' => $user->email,     // unique to customers
                    'reference' => $reference,   // unique to transactions
                    'metadata' => $builder->build(),
                    'callback_url' => $this->request->get(
                        'redirect',
                        Providers::config('payment_verify_url')
                    ),
                ]);
            }

            $code = HttpStatus::OK;
            $msg = 'Transaction initialized';

            // Call the callback function
            if ($callback) {
                $tranx = new CustomObject($tranx);
                $response = $callback($reference, $tranx, $real_due, $msg, $code);
                if ($respond) {
                    return $response;
                }
            }
        } catch (ApiException|\InvalidArgumentException|\ErrorException $e) {
            $msg = $e->getMessage();
            $code = $e instanceof ApiException ? HttpStatus::BAD_REQUEST : HttpStatus::SERVER_ERROR;
            $response->error = $code->value;
            // Call the error callback function
            if ($error_callback) {
                $response = $error_callback($msg, $code);
                if (is_object($response)) {
                    $response->error = $code->value;
                }
                if ($respond) {
                    return $response;
                }
            }
        }

        if (! $error_callback && ! $callback) {
            $response->amount = $due;
            $response->message = $msg;
            $response->payload = $tranx;
            $response->reference = $reference;
            $response->status_code = $code;
        }

        // Return the response as a collection
        return new CustomObject($response);
    }

    /**
     * Verify a payment intent
     *
     * @param  callable  $successCallback
     * @param  callable  $errorCallback  The callback function to call when an error occurs
     * @param  bool  $respond  Wether to return a response or not.
     * @return array|object|CustomObject
     */
    public function verify(
        string $reference,
        ?callable $callback = null,
        ?callable $error_callback = null,
        ?bool $respond = true
    ) {
        $response = new \stdClass;
        $code = HttpStatus::BAD_REQUEST;
        $msg = 'Transaction Failed';

        $this->request->merge(compact('reference'));

        if (! $this->request->reference) {
            $msg = 'No transaction reference supplied';
            $tranx = $response;
            $response->error = $code->value;

            // Call the error callback function
            if ($error_callback) {
                $tranx = new CustomObject($tranx);
                $response = $error_callback($msg, $code, $tranx);
                if (is_object($response)) {
                    $response->error = $code->value;
                }
                if ($respond) {
                    return $response;
                }
            }
        } else {
            try {
                $paystack = new Paystack((string) config('services.paystack.secret_key'));
                $tranx = $paystack->transaction->verify([
                    'reference' => $this->request->reference,   // unique to transactions
                ]);

                $tranx->payment_approved = $tranx->data->status === 'success';

                $code = HttpStatus::OK;
                $msg = 'Transaction verified';

                // Call the callback function
                if ($callback) {
                    $tranx = new CustomObject($tranx);
                    $response = $callback($this->request->reference, $tranx, $msg, $code);
                    if ($respond) {
                        return $response;
                    }
                }
            } catch (ApiException|\InvalidArgumentException|\ErrorException|\Exception $e) {
                $tranx = $e instanceof ApiException ? $e->getResponseObject() : new \stdClass;
                $code = HttpStatus::UNPROCESSABLE_ENTITY;
                $msg = $e->getMessage();
                $response->error = $code->value;

                // Call the error callback function
                if ($error_callback) {
                    $tranx = new CustomObject($tranx);
                    $response = $error_callback($msg, $code, $tranx);
                    if (is_object($response)) {
                        $response->error = $code->value;
                    }
                    if ($respond) {
                        return $response;
                    }
                }
            }
        }

        if (! $error_callback && ! $callback) {
            $response->message = $msg;
            $response->payload = $tranx;
            $response->status_code = $code;
            $response->payment_approved = $tranx->payment_approved ?? false;
        }

        return new CustomObject($response);
    }

    /**
     * Deauthorize a transaction payment
     *
     * @param  callable  $successCallback
     * @param  callable  $errorCallback
     * @param  bool  $respond  Wether to return a response or not.
     */
    public function deauthorize(
        string $authorization_code,
        ?callable $callback = null,
        ?callable $error_callback = null,
        ?bool $respond = true
    ) {
        $response = new \stdClass;
        $code = HttpStatus::BAD_REQUEST;
        $msg = 'Transaction Failed';

        if (! $authorization_code) {
            $msg = 'No authorization code supplied';
            $tranx = $response;
        } else {
            try {
                $paystack = new Paystack((string) config('services.paystack.secret_key'));
                $tranx = $paystack->customer->deactivateAuthorization($authorization_code);
            } catch (ApiException|\InvalidArgumentException|\ErrorException $e) {
                $tranx = $e instanceof ApiException ? $e->getResponseObject() : new \stdClass;
                $code = HttpStatus::UNPROCESSABLE_ENTITY;
                $msg = $e->getMessage();

                // Call the error callback function
                if ($error_callback) {
                    $tranx = new CustomObject($tranx);
                    $response = $error_callback($msg, $code, $tranx);
                    if ($respond) {
                        return $response;
                    }
                }
            }
        }

        if (! $error_callback && ! $callback) {
            $response->message = $msg;
            $response->payload = $tranx;
            $response->status_code = $code;
        }

        return new CustomObject($response);
    }

    /**
     * Do transfter to the specified user
     *
     * @param  ?callable  $errorCallback  The callback function to call when an error occurs
     * @param  bool  $respond  Wether to return a response or not.
     * @return array|object|CustomObject
     */
    public function transfer(
        int|float $amount,
        ?string $reason = null,
        ?callable $successCallback = null,
        ?callable $errorCallback = null,
        ?bool $respond = true
    ) {
        $response = new \stdClass;
        $code = HttpStatus::BAD_REQUEST;
        $user = $this->user;
        $msg = 'Transfer Failed';

        $recipient = null;
        $paystack = new Paystack((string) config('services.paystack.secret_key'));

        try {
            $recipient_code = $user->data['bank']['recipient_code'] ?? null;
            if (! $recipient_code) {
                $recipient = $paystack->transferrecipient->create([
                    'type' => 'nuban',
                    'name' => $user->data['bank']['account_name'] ?? $user->fullname,
                    'account_number' => $user->data['bank']['nuban'],
                    'bank_code' => $user->data['bank']['bank_code'],
                    'currency' => 'NGN',
                ]);

                $recipient_code = $recipient->data->recipient_code;

                $user->data = $user->data->merge([
                    'bank' => [...$user->data['bank'], 'recipient_code' => $recipient_code],
                ]);

                $user->save();
            }

            $reference = Providers::config('reference_prefix', 'TRX-') . Random::string(20, ! 1, ! 0, ! 0, ! 1);

            $tranx = $paystack->transfer->initiate([
                'source' => 'balance',
                'amount' => $amount,
                'reason' => $reason,
                'currency' => 'NGN',
                'reference' => $reference,
                'recipient' => $recipient_code,
            ]);

            $code = HttpStatus::OK;
            $msg = 'Transfer Completed';

            // Call the callback function
            if ($successCallback) {
                $tranx = new CustomObject($tranx);
                $response = $successCallback($tranx, $msg, $code);
                if ($respond) {
                    return $response;
                }
            }
        } catch (ApiException $e) {
            $tranx = $e instanceof ApiException ? $e->getResponseObject() : new \stdClass;
            $code = HttpStatus::UNPROCESSABLE_ENTITY;
            $msg = $e->getMessage();

            if ($errorCallback) {
                $tranx = new CustomObject($tranx);
                $response = $errorCallback($msg, $code, $tranx);
                if ($respond) {
                    return $response;
                }
            }
        }

        if (! $errorCallback && ! $successCallback) {
            $response->message = $msg;
            $response->payload = $tranx;
            $response->status_code = $code;
        }

        return new CustomObject($response);
    }
}
