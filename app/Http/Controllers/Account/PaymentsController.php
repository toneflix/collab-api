<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\TransactionCollection;
use App\Services\Limits;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    /**
     * What the user has paid for.
     */
    public function purchases(Request $request)
    {
        $purchases = $this->user->transactions()
            ->where('status', 'complete')
            ->whereNotNull('purpose')
            ->when($request->purpose, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->latest('paid_at')
            ->paginate($request->input('limit', 15));

        return (new TransactionCollection($purchases))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * What the creator has earned from sales and tips.
     */
    public function earnings(Request $request)
    {
        $earnings = $this->user->earnings();

        $totals = (clone $earnings)
            ->selectRaw('purpose, count(*) as count, sum(amount) as gross, sum(recipient_share) as earned')
            ->groupBy('purpose')
            ->get()
            ->keyBy('purpose')
            ->map(fn ($row) => [
                'count' => (int) $row->count,
                'gross' => (float) $row->gross,
                'earned' => (float) $row->earned,
            ]);

        $list = $earnings
            ->when($request->purpose, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->latest('paid_at')
            ->paginate($request->input('limit', 15));

        return (new TransactionCollection($list))->additional([
            'summary' => [
                'earned' => (float) $totals->sum('earned'),
                'currency' => Providers::config('app_currency', 'NGN'),
                'byPurpose' => $totals,
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * The user's Premium status and the plans on offer.
     */
    public function subscription()
    {
        $current = $this->user->subscriptions()->active()->first();
        $latest = $this->user->subscriptions()->first();

        return Providers::response()->success([
            'data' => [
                'premium' => (bool) $current,
                // When the paid time runs out, including periods paid in advance
                'endsAt' => $current ? $latest?->ends_at : null,
                'current' => $current ? new SubscriptionResource($current) : null,
                'plans' => [
                    'monthly' => (float) Providers::config('premium_monthly_price', 0),
                    'yearly' => (float) Providers::config('premium_yearly_price', 0),
                ],
                'currency' => Providers::config('app_currency', 'NGN'),
                // 0 means no limit
                'limits' => [
                    'free' => Limits::forTier('free'),
                    'premium' => Limits::forTier('premium'),
                ],
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }
}
