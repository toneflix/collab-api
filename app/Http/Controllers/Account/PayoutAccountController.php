<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Services\Payment\Payouts;
use Illuminate\Http\Request;

/**
 * The bank account a creator withdraws to.
 */
class PayoutAccountController extends Controller
{
    public function banks(Payouts $payouts)
    {
        return Providers::response()->success([
            'data' => $payouts->banks(),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function show()
    {
        $account = $this->user->payoutAccount;

        return Providers::response()->success([
            'data' => $account ? new PayoutAccountResource($account) : null,
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * The name on an account, for the creator to confirm before saving it.
     */
    public function resolve(Request $request, Payouts $payouts)
    {
        $valid = $this->validated($request);

        return Providers::response()->success([
            'data' => $payouts->resolve($valid['bank_code'], $valid['account_number']),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function update(Request $request, Payouts $payouts)
    {
        $valid = $this->validated($request);

        $account = $payouts->saveAccount($this->user, $valid['bank_code'], $valid['account_number']);

        return Providers::response()->success([
            'data' => new PayoutAccountResource($account),
            'message' => 'Your payout account was saved.',
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
     * @return array{bank_code: string, account_number: string}
     */
    protected function validated(Request $request): array
    {
        return $this->validate($request, [
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
        ], [
            'account_number.regex' => 'Enter the 10 digit account number.',
        ]);
    }
}
