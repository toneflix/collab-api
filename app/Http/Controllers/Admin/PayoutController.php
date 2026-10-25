<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Services\Payment\Payouts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets admins review creators' withdrawal requests.
 */
class PayoutController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-payouts'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to manage payouts.'
            );

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PayoutStatus::class)],
        ]);

        $list = Payout::with(['user', 'reviewer'])
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->search, fn($q, $search) => $q->where(fn($q) => $q
                ->where('reference', $search)
                ->orWhereHas('user', fn($q) => $q->whereLike('username', "%{$search}%")->orWhereLike('email', "%{$search}%"))))
            ->latest()
            ->paginate(min((int) $request->input('limit', 20), 50));

        $pending = Payout::status(PayoutStatus::PENDING)
            ->toBase()
            ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount')
            ->first();

        return PayoutResource::collection($list)->additional([
            'summary' => [
                'pendingCount' => (int) $pending->count,
                'pendingAmount' => (float) $pending->amount,
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    public function approve(Request $request, Payouts $payouts, Payout $payout)
    {
        $payout = $payouts->approve($payout, $request->user())->load(['user', 'reviewer']);

        return (new PayoutResource($payout))->additional([
            'message' => match ($payout->status) {
                PayoutStatus::FAILED => 'Paystack did not accept this transfer: ' . $payout->failure_reason,
                PayoutStatus::PAID => 'The withdrawal was paid.',
                default => 'The withdrawal was sent to Paystack.',
            },
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    public function reject(Request $request, Payouts $payouts, Payout $payout)
    {
        $valid = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $payout = $payouts->reject($payout, $request->user(), $valid['reason'] ?? null)->load(['user', 'reviewer']);

        return (new PayoutResource($payout))->additional([
            'message' => 'The withdrawal was rejected.',
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Check with Paystack on a transfer that is taking a while.
     */
    public function sync(Payouts $payouts, Payout $payout)
    {
        $payout = $payouts->sync($payout)->load(['user', 'reviewer']);

        return (new PayoutResource($payout))->additional([
            'message' => 'The withdrawal is ' . $payout->status->value . '.',
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
