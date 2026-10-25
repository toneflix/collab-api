<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Services\Payment\Payouts;
use Illuminate\Http\Request;

/**
 * The bank, mobile money or provider-hosted account a creator withdraws to.
 */
class PayoutAccountController extends Controller
{
    /**
     * Countries creators can be paid in, and the kinds of account each takes.
     */
    public function countries(Payouts $payouts)
    {
        return Providers::response()->success([
            'data' => $payouts->countries(),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function banks(Request $request, Payouts $payouts)
    {
        $valid = $this->validate($request, [
            'country' => ['required', 'string', 'size:2'],
            'method' => ['nullable', 'string', 'max:30'],
        ]);

        return Providers::response()->success([
            'data' => $payouts->banks(strtoupper($valid['country']), $valid['method'] ?? 'bank'),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function show(Payouts $payouts)
    {
        $account = $payouts->account($this->user);

        if (! $account) {
            return Providers::response()->success(['data' => null, 'message' => HttpStatus::message(HttpStatus::OK)]);
        }

        return (new PayoutAccountResource($account))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * The name on an account, for the creator to confirm before saving it.
     */
    public function resolve(Request $request, Payouts $payouts)
    {
        $valid = $this->validated($request);

        return Providers::response()->success([
            'data' => $payouts->resolve($valid['country'], $valid['method'], $valid['bank_code'], $valid['account_number'], $valid['details']),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function update(Request $request, Payouts $payouts)
    {
        $valid = $this->validated($request);

        $account = $payouts->saveAccount($this->user, $valid['country'], $valid['method'], $valid['bank_code'], $valid['account_number'], $valid['details']);

        return (new PayoutAccountResource($account))->additional([
            'message' => 'Your payout account was saved.',
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ])->response()->setStatusCode(HttpStatus::OK->value);
    }

    /**
     * Send the creator to the provider to add their bank details there.
     */
    public function connect(Request $request, Payouts $payouts)
    {
        $valid = $this->validate($request, [
            'country' => ['required', 'string', 'size:2'],
            'return_url' => ['required', 'url', 'max:500'],
        ]);

        $this->ensureOwnOrigin($valid['return_url']);

        $url = $payouts->connect($this->user, strtoupper($valid['country']), $valid['return_url']);

        return Providers::response()->success([
            'data' => ['url' => $url],
            'message' => 'Continue with the provider to finish.',
        ]);
    }

    public function destroy()
    {
        $this->user->payoutAccount()->delete();

        return Providers::response()->success([
            'data' => null,
            'message' => 'Your payout account was removed.',
        ]);
    }

    /**
     * @return array{country: string, method: string, bank_code: string, account_number: string, details: array<string, string|null>}
     */
    protected function validated(Request $request): array
    {
        $valid = $this->validate($request, [
            'country' => ['required', 'string', 'size:2'],
            'method' => ['nullable', 'string', 'max:30'],
            'bank_code' => ['required', 'string', 'max:30'],
            // The pattern for each kind of account is checked by the payout service.
            'account_number' => ['required', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_type' => ['nullable', 'in:personal,business'],
            'document_type' => ['nullable', 'in:identityNumber,passportNumber,businessRegistrationNumber'],
            'document_number' => ['nullable', 'string', 'max:40'],
        ]);

        return [
            'country' => strtoupper($valid['country']),
            'method' => $valid['method'] ?? 'bank',
            'bank_code' => $valid['bank_code'],
            'account_number' => preg_replace('/\s+/', '', $valid['account_number']),
            'details' => array_filter([
                'account_name' => $valid['account_name'] ?? null,
                'account_type' => $valid['account_type'] ?? null,
                'document_type' => $valid['document_type'] ?? null,
                'document_number' => $valid['document_number'] ?? null,
            ]),
        ];
    }

    /**
     * Only send creators back to our own app after onboarding.
     */
    protected function ensureOwnOrigin(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $allowed = collect([config('app.url'), config('app.frontend_url'), ...(array) config('cors.allowed_origins', [])])
            ->filter(fn($origin) => is_string($origin) && $origin !== '*')
            ->map(fn($origin) => parse_url($origin, PHP_URL_HOST))
            ->filter();

        abort_unless($host && $allowed->contains($host), HttpStatus::UNPROCESSABLE_ENTITY->value, 'That return link is not allowed.');
    }
}
