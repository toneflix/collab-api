<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\ReferredUserCollection;
use App\Http\Resources\ReferrerResource;
use App\Models\Transaction;
use App\Services\Referrals\Referrals;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

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
     * The people the user invited, newest first, with what each one's payments earned.
     */
    public function invites(Request $request)
    {
        $userId = $this->user->id;

        $invites = $this->user
            ->referrals()
            ->select('users.*')
            ->selectSub(
                fn(Builder $q) => $q
                    ->from('transactions as rewards')
                    ->join('transactions as payments', 'payments.id', '=', 'rewards.transactable_id')
                    ->where('rewards.transactable_type', (new Transaction)->getMorphClass())
                    ->where('rewards.purpose', Referrals::PURPOSE)
                    ->where('rewards.recipient_id', $userId)
                    ->whereColumn('payments.user_id', 'users.id')
                    ->selectRaw('coalesce(sum(rewards.recipient_share), 0)'),
                'referral_earned',
            )
            ->latest('referred_at')
            ->paginate($request->perPage(20));

        return (new ReferredUserCollection($invites))
            ->additional([
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
