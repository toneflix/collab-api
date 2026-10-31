<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\ReferredUserCollection;
use App\Http\Resources\ReferrerResource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Referrals\Referrals;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReferralController extends Controller
{
    /**
     * The user's invite code, the terms they earn on, and what invites have earned.
     */
    public function show(Referrals $referrals)
    {
        $summary = $referrals->summary($this->user);

        return (new ActionResource($summary))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Change the user's invite code.
     */
    public function update(Request $request, Referrals $referrals)
    {
        $this->validate($request, [
            'code' => ['required', ...Referrals::CODE_RULES],
        ]);

        $referrals->setCode($this->user, $request->string('code')->toString());

        $summary = $referrals->summary($this->user);

        return (new ActionResource($summary))
            ->additional([
                'message' => 'Invite code saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * The people the user invited with what each one's payments earned, in total
     * and in the month asked for. A month lists the biggest earners first.
     */
    public function invites(Request $request, Referrals $referrals)
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $userId = $this->user->id;
        $month = $request->filled('month')
            ? Carbon::createFromFormat('!Y-m', $request->string('month')->toString())->startOfMonth()
            : null;
        $terms = $referrals->terms($this->user);
        $rewards = fn(Builder $q) => $q
            ->from('transactions as rewards')
            ->join('transactions as payments', 'payments.id', '=', 'rewards.transactable_id')
            ->where('rewards.transactable_type', (new Transaction)->getMorphClass())
            ->where('rewards.purpose', Referrals::PURPOSE)
            ->where('rewards.recipient_id', $userId)
            ->whereColumn('payments.user_id', 'users.id');
        $inMonth = fn(Builder $q) => $q->whereBetween('rewards.paid_at', [$month, $month?->copy()->endOfMonth()]);

        $invites = $this->user
            ->referrals()
            ->select('users.*')
            ->selectSub(fn(Builder $q) => $rewards($q)->selectRaw('coalesce(sum(rewards.recipient_share), 0)'), 'referral_earned')
            ->selectSub(fn(Builder $q) => $rewards($q)->selectRaw('count(*)'), 'referral_payments')
            ->when(
                $month,
                fn($q) => $q
                    ->selectSub(fn(Builder $q) => $inMonth($rewards($q))->selectRaw('coalesce(sum(rewards.recipient_share), 0)'), 'month_earned')
                    ->selectSub(fn(Builder $q) => $inMonth($rewards($q))->selectRaw('count(*)'), 'month_payments')
                    ->orderByDesc('month_earned'),
            )
            ->latest('referred_at')
            ->paginate($request->perPage(20));

        $invites
            ->getCollection()
            ->each(fn(User $invitee) => $invitee->setAttribute(
                'rewards_end_at',
                $terms->months > 0 && $invitee->referred_at
                    ? Carbon::parse($invitee->referred_at)->addMonths($terms->months)
                    : null,
            ));

        return (new ReferredUserCollection($invites))
            ->additional([
                'month' => $month?->format('Y-m'),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Who owns an invite code, for the sign-up page.
     */
    public function lookup(string $code, Referrals $referrals)
    {
        $referrer = $referrals->findReferrer($code);

        abort_unless($referrer, HttpStatus::NOT_FOUND->value, 'That invite link is not valid.');

        return (new ReferrerResource($referrer))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
