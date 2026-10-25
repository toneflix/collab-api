<?php

namespace App\Http\Controllers\Payment;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Services\Payment\FlutterwaveGateway;
use App\Services\Payment\Payouts;
use App\Services\Payment\StripeConnectGateway;
use Illuminate\Http\Request;

/**
 * Flutterwave and Stripe call these when a payout transfer settles or is reversed.
 * The body only says which payout changed; its status is always asked from the provider.
 */
class PayoutWebhookController extends Controller
{
    public function flutterwave(Request $request, FlutterwaveGateway $gateway, Payouts $payouts)
    {
        abort_unless($gateway->validWebhook($request->header('verif-hash')), HttpStatus::UNAUTHORIZED->value, 'Invalid signature.');

        if (str_starts_with((string) $request->input('event', $request->input('event.type')), 'transfer')) {
            $this->sync($payouts, 'flutterwave', (string) $request->input('data.reference'));
        }

        return response()
            ->json(['status' => 'ok']);
    }

    public function stripe(Request $request, StripeConnectGateway $gateway, Payouts $payouts)
    {
        $event = $gateway->webhookEvent($request->getContent(), $request->header('Stripe-Signature'));

        abort_unless($event, HttpStatus::UNAUTHORIZED->value, 'Invalid signature.');

        if (str_starts_with($event->type, 'transfer.')) {
            $transfer = $event->data->object;
            $this->sync($payouts, 'stripe', (string) ($transfer->metadata->reference ?? $transfer->transfer_group ?? ''));
        }

        return response()
            ->json(['status' => 'ok']);
    }

    protected function sync(Payouts $payouts, string $provider, string $reference): void
    {
        if ($reference === '') {
            return;
        }

        $payout = Payout::where('reference', $reference)
            ->where('provider', $provider)
            ->first();

        if ($payout) {
            $payouts->sync($payout);
        }
    }
}
