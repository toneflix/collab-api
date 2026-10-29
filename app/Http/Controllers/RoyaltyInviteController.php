<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\RoyaltyContractResource;
use App\Http\Resources\RoyaltyInviteResource;
use App\Models\RoyaltyInvite;
use App\Services\Referrals\Royalties;

/**
 * The invited person's side of a royalty invite: reading it and accepting it.
 */
class RoyaltyInviteController extends Controller
{
    public function __construct(protected Royalties $royalties)
    {
        parent::__construct();
    }

    public function show(string $token)
    {
        $invite = RoyaltyInvite::query()
            ->where('token', $token)
            ->first();

        abort_unless($invite, HttpStatus::NOT_FOUND->value, 'This invite link is not valid.');

        return (new RoyaltyInviteResource($invite))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Accept the invite as the signed-in user.
     */
    public function accept(string $token)
    {
        $invite = RoyaltyInvite::query()
            ->where('token', $token)
            ->first();

        abort_unless($invite, HttpStatus::NOT_FOUND->value, 'This invite link is not valid.');

        $contract = $this->royalties->accept($invite, $this->user);

        return (new RoyaltyContractResource($contract))
            ->additional([
                'message' => 'You\'re in the royalty program.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }
}
