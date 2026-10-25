<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use App\Services\Payment\Payouts;
use App\Services\Payment\PayoutSettings;
use Closure;
use Illuminate\Http\Request;

/**
 * Lets admins choose which providers pay creators. Their keys come from `.env`.
 */
class PayoutSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change payout settings.'
            );

            return $next($request);
        });
    }

    public function show(Payouts $payouts)
    {
        return $this->settings($payouts);
    }

    public function update(Request $request, Payouts $payouts)
    {
        $valid = $request->validate([
            'paystack_payouts_enabled' => ['nullable', 'boolean'],
            'flutterwave_payouts_enabled' => ['nullable', 'boolean'],
            'stripe_payouts_enabled' => ['nullable', 'boolean'],
            'stripe_platform_country' => ['nullable', 'string', 'size:2'],
            'stripe_currency' => ['nullable', 'string', 'size:3'],
            'stripe_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        foreach (['stripe_platform_country', 'stripe_currency'] as $key) {
            if (isset($valid[$key])) {
                $valid[$key] = strtoupper($valid[$key]);
            }
        }

        PayoutSettings::update($valid);

        return $this->settings($payouts, 'Payout settings saved.', HttpStatus::ACCEPTED);
    }

    protected function settings(Payouts $payouts, string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        // Which provider pays each country with the current settings
        $countries = collect($payouts->countries())
            ->map(fn($c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'provider' => $c['provider'],
                'currency' => $c['currency'],
            ])
            ->all();

        $settings = [
            'fields' => PayoutSettings::forAdmin(),
            'keys' => PayoutSettings::keys(),
            'currency' => $payouts->currency(),
            'webhooks' => [
                'paystack' => route('payments.webhook'),
                'flutterwave' => route('payouts.webhook.flutterwave'),
                'stripe' => route('payouts.webhook.stripe'),
            ],
            'countries' => $countries,
        ];

        return (new SettingsResource($settings))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
