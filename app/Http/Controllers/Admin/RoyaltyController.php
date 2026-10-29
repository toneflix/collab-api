<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoyaltyContractCollection;
use App\Http\Resources\RoyaltyContractResource;
use App\Http\Resources\RoyaltyInviteCollection;
use App\Http\Resources\RoyaltyInviteResource;
use App\Models\RoyaltyContract;
use App\Models\RoyaltyInvite;
use App\Services\Referrals\Referrals;
use App\Services\Referrals\Royalties;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The royalty program: invites to partners and the contracts they earn on.
 */
class RoyaltyController extends Controller
{
    public function __construct(protected Royalties $royalties)
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-payouts'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to manage royalties.'
            );

            return $next($request);
        });
    }

    public function invites(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in([RoyaltyInvite::PENDING, RoyaltyInvite::ACCEPTED, RoyaltyInvite::CANCELLED, RoyaltyInvite::EXPIRED])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = $request->string('search')->trim()->lower()->toString();

        $invites = RoyaltyInvite::query()
            ->with('invitedBy')
            ->status($request->input('status'))
            ->when(
                $search !== '',
                fn(Builder $q) => $q->where(
                    fn(Builder $q) => $q
                        ->where('email', 'like', "%{$search}%")
                        ->orWhereRaw('lower(name) like ?', ["%{$search}%"])
                )
            )
            ->latest('id')
            ->paginate($request->perPage(20));

        return (new RoyaltyInviteCollection($invites))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function invite(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'months' => ['nullable', 'integer', 'between:0,120'],
            'code' => ['nullable', ...Referrals::CODE_RULES],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $invite = $this->royalties->invite($request->user(), $data);

        $invite->load('invitedBy');

        return (new RoyaltyInviteResource($invite))
            ->additional([
                'message' => 'Invite sent.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function resend(Request $request, RoyaltyInvite $invite)
    {
        $this->royalties->resend($request->user(), $invite);

        $invite->load('invitedBy');

        return (new RoyaltyInviteResource($invite))
            ->additional([
                'message' => 'Invite sent again.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function cancel(Request $request, RoyaltyInvite $invite)
    {
        $this->royalties->cancel($request->user(), $invite);

        $invite->load('invitedBy');

        return (new RoyaltyInviteResource($invite))
            ->additional([
                'message' => 'Invite cancelled.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Royals' contracts, newest first, with how many people each royal invited
     * and what the contract earned them.
     */
    public function contracts(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'ended'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = $request->string('search')->trim()->lower()->toString();
        $status = $request->input('status');

        $contracts = RoyaltyContract::query()
            ->with('user')
            ->select('royalty_contracts.*')
            ->selectSub(
                fn(QueryBuilder $q) => $q
                    ->from('users as invited')
                    ->whereColumn('invited.referred_by_id', 'royalty_contracts.user_id')
                    ->selectRaw('count(*)'),
                'invited_count',
            )
            ->selectSub(
                fn(QueryBuilder $q) => $q
                    ->from('transactions')
                    ->where('transactions.purpose', Referrals::PURPOSE)
                    ->where('transactions.status', 'complete')
                    ->whereColumn('transactions.recipient_id', 'royalty_contracts.user_id')
                    ->whereColumn('transactions.paid_at', '>=', 'royalty_contracts.starts_at')
                    ->where(
                        fn(QueryBuilder $q) => $q
                            ->whereNull('royalty_contracts.ends_at')
                            ->orWhereColumn('transactions.paid_at', '<', 'royalty_contracts.ends_at')
                    )
                    ->selectRaw('coalesce(sum(transactions.recipient_share), 0)'),
                'royalty_earned',
            )
            ->when($status === 'active', fn(Builder $q) => $q->active())
            ->when($status === 'ended', fn(Builder $q) => $q->whereNotNull('ends_at')->where('ends_at', '<=', now()))
            ->when(
                $search !== '',
                fn(Builder $q) => $q->whereHas(
                    'user',
                    fn(Builder $q) => $q
                        ->whereRaw('lower(email) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(username) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(firstname) like ?', ["%{$search}%"])
                )
            )
            ->latest('id')
            ->paginate($request->perPage(20));

        return (new RoyaltyContractCollection($contracts))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function update(Request $request, RoyaltyContract $contract)
    {
        $data = $request->validate([
            'rate' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'months' => ['sometimes', 'integer', 'between:0,120'],
            'code' => ['sometimes', 'nullable', ...Referrals::CODE_RULES],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $current = $this->royalties->change($request->user(), $contract, $data);

        $current->load('user');

        return (new RoyaltyContractResource($current))
            ->additional([
                'message' => 'Contract saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function end(Request $request, RoyaltyContract $contract)
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->royalties->end($request->user(), $contract, $request->input('reason'));

        $contract->load('user');

        return (new RoyaltyContractResource($contract))
            ->additional([
                'message' => 'Contract ended.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
