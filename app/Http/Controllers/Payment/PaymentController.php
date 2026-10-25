<?php

namespace App\Http\Controllers\Payment;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Payout;
use App\Models\Transaction;
use App\Services\Payment\Checkout;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\Payouts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Start a payment for Premium, a release, stems, a tip or AI credits.
     */
    public function store(Request $request, Checkout $checkout)
    {
        $valid = $this->validate($request, [
            'purpose' => ['required', Rule::in(Checkout::PURPOSES)],
            'id' => ['required_unless:purpose,premium,credits', 'nullable'],
            'plan' => ['required_if:purpose,premium', 'nullable'],
            'cycles' => ['nullable', 'integer', 'min:1', 'max:120'],
            'amount' => ['required_if:purpose,tip', 'nullable', 'numeric'],
            'message' => ['nullable', 'string', 'max:280'],
            'credits' => ['required_if:purpose,credits', 'nullable', 'integer', 'min:1'],
        ]);

        $session = $checkout->start($this->user, $valid['purpose'], $valid);

        return Providers::response()->success([
            'data' => [
                'reference' => $session['transaction']->reference,
                'amount' => $session['transaction']->due,
                'access_code' => $session['access_code'],
                'authorization_url' => $session['authorization_url'],
            ],
            'message' => 'Payment started.',
        ], HttpStatus::CREATED);
    }

    /**
     * Confirm a payment with the gateway after the customer pays.
     */
    public function verify(Checkout $checkout, string $reference)
    {
        /** @var Transaction $transaction */
        $transaction = $this->user->transactions()->where('reference', $reference)->firstOrFail();

        $transaction = $checkout->verify($transaction);

        $paid = $transaction->status === 'complete';

        return (new TransactionResource($transaction))
            ->additional([
                'message' => $paid ? 'Payment successful.' : 'We could not confirm this payment.',
                'status' => $paid ? 'success' : 'error',
                'status_code' => $paid ? HttpStatus::OK : HttpStatus::BAD_REQUEST,
            ])
            ->response()
            ->setStatusCode($paid ? HttpStatus::OK->value : HttpStatus::BAD_REQUEST->value);
    }

    /**
     * Paystack calls this when a charge succeeds, even if the customer closed the page,
     * and when a payout transfer succeeds, fails or is reversed.
     */
    public function webhook(Request $request, Checkout $checkout, PaymentGateway $gateway, Payouts $payouts)
    {
        if (! $gateway->validWebhook($request->getContent(), $request->header('x-paystack-signature'))) {
            abort(HttpStatus::UNAUTHORIZED->value, 'Invalid signature.');
        }

        if ($request->input('event') === 'charge.success') {
            $transaction = Transaction::where('reference', $request->input('data.reference'))->first();

            // Ask Paystack directly rather than trusting the webhook body.
            if ($transaction) {
                $checkout->verify($transaction);
            }
        }

        if (str_starts_with((string) $request->input('event'), 'transfer.')) {
            $payout = Payout::where('reference', $request->input('data.reference'))->first();

            if ($payout) {
                $payouts->sync($payout);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
