<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Services\Payment\Payouts;
use Illuminate\Http\Request;

/**
 * A creator's withdrawals and the balance they can withdraw from.
 */
class PayoutController extends Controller
{
    public function index(Request $request, Payouts $payouts)
    {
        $list = $this->user->payouts()
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(min((int) $request->input('limit', 15), 50));

        $account = $this->user->payoutAccount;

        return PayoutResource::collection($list)->additional([
            'summary' => $payouts->summary($this->user),
            'account' => $account ? new PayoutAccountResource($account) : null,
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    public function store(Request $request, Payouts $payouts)
    {
        $valid = $this->validate($request, [
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $payout = $payouts->request($this->user, (float) $valid['amount']);

        return (new PayoutResource($payout))->additional([
            'summary' => $payouts->summary($this->user),
            'message' => 'Your withdrawal request was received.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Cancel a request that is still waiting for approval.
     */
    public function destroy(Payouts $payouts, Payout $payout)
    {
        abort_unless((int) $payout->user_id === (int) $this->user->id, HttpStatus::NOT_FOUND->value);

        $payout = $payouts->cancel($payout);

        return (new PayoutResource($payout))->additional([
            'summary' => $payouts->summary($this->user),
            'message' => 'Your withdrawal was cancelled.',
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
