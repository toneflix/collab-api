<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InterestsResource;
use App\Services\Account\Interests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Who the signed-in user is and the genres and topics they like, which
 * curate their playlists.
 */
class InterestsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return (new InterestsResource($user))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $type = $request->input('account_type', $user->account_type?->value);

        $valid = $request->validate([
            ...Interests::identityRules($type),
            ...Interests::pickRules(),
        ], [
            'identities.*.in' => 'Pick from the options for your account type.',
        ]);

        if (Interests::picksNothing($valid)) {
            throw ValidationException::withMessages(['genres' => 'Pick at least one genre or topic.']);
        }

        Interests::apply($user, $valid);

        return (new InterestsResource($user))
            ->additional([
                'message' => 'Your interests are saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
