<?php

namespace App\Http\Controllers\Payment;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentOptionsResource;
use App\Http\Resources\PaymentSessionResource;
use App\Http\Resources\TransactionResource;
use App\Models\Payout;
use App\Models\Transaction;
use App\Services\Payment\Checkout;
use App\Services\Payment\PaymentGateways;
use App\Services\Payment\PaymentRegions;
use App\Services\Payment\Payouts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Start a payment for Premium, a release, stems, a tip or AI credits.
     */
    public function store(Request $request, Checkout $checkout, PaymentRegions $regions, PaymentGateways $gateways)
    {
        $valid = $this->validate($request, [
            'provider' => ['nullable', 'string', Rule::in($gateways->keys())],
            'inline' => ['nullable', 'boolean'],
            'return_path' => ['nullable', 'string', 'max:500', 'regex:/^\/(?![\/\\\\])/'],
            'purpose' => ['required', Rule::in(Checkout::PURPOSES)],
            'id' => ['required_unless:purpose,premium,credits', 'nullable'],
            'plan' => ['required_if:purpose,premium', 'nullable'],
            'cycles' => ['nullable', 'integer', 'min:1', 'max:120'],
            'amount' => ['required_if:purpose,tip', 'nullable', 'numeric'],
            'message' => ['nullable', 'string', 'max:280'],
            'credits' => ['required_if:purpose,credits', 'nullable', 'integer', 'min:1'],
        ]);

        $options = $regions->forUser($this->user, $request->header('CF-IPCountry'));
        $session = $checkout->start($this->user, $valid['purpose'], $valid, $options);

        return (new PaymentSessionResource($session))
            ->additional([
                'message' => 'Payment started.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * The currency the user pays in and the providers they can choose from.
     */
    public function options(Request $request, PaymentRegions $regions)
    {
        $options = $regions
            ->forUser($this->user, $request->header('CF-IPCountry'))
            ->toArray();

        return (new PaymentOptionsResource($options))
            ->additional([
                'message' => 'OK',
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Confirm a payment with the gateway after the customer pays.
     */
    public function verify(Checkout $checkout, string $reference)
    {
        /** @var Transaction $transaction */
        $transaction = $this->user
            ->transactions()
            ->where('reference', $reference)
            ->firstOrFail();

        $transaction = $checkout->verify($transaction);

        $status = match ($transaction->status) {
            'complete' => HttpStatus::OK,
            'pending' => HttpStatus::ACCEPTED,
            default => HttpStatus::BAD_REQUEST,
        };

        return (new TransactionResource($transaction))
            ->additional([
                'message' => match ($status) {
                    HttpStatus::OK => 'Payment successful.',
                    HttpStatus::ACCEPTED => 'Waiting for the payment to go through.',
                    default => 'We could not confirm this payment.',
                },
                'status' => $status === HttpStatus::BAD_REQUEST ? 'error' : 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }

    /**
     * Paystack calls this when a charge succeeds, even if the customer closed the page,
     * and when a payout transfer succeeds, fails or is reversed.
     */
    public function webhook(Request $request, Checkout $checkout, PaymentGateways $gateways, Payouts $payouts)
    {
        $gateway = $gateways->get('paystack');

        if (! $gateway->validWebhook($request->getContent(), $request->header('x-paystack-signature'))) {
            abort(HttpStatus::UNAUTHORIZED->value, 'Invalid signature.');
        }

        if ($request->input('event') === 'charge.success') {
            $transaction = Transaction::where('reference', $request->input('data.reference'))
                ->first();

            // Ask Paystack directly rather than trusting the webhook body.
            if ($transaction) {
                $checkout->verify($transaction);
            }
        }

        if (str_starts_with((string) $request->input('event'), 'transfer.')) {
            $payout = Payout::where('reference', $request->input('data.reference'))
                ->first();

            if ($payout) {
                $payouts->sync($payout);
            }
        }

        return response()
            ->json(['status' => 'ok']);
    }

}
