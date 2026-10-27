<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Models\User;
use App\Services\Account\Deactivation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user deactivating or deleting their own account.
 */
class DeactivationController extends Controller
{
    public function deactivate(Request $request)
    {
        $user = $this->confirmedUser($request);

        Deactivation::deactivate($user);

        $result = ['deactivated' => true, 'deletesAt' => null];

        return (new ActionResource($result))
            ->additional([
                'message' => __('Your account is deactivated. Sign in any time to bring it back.'),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function destroy(Request $request)
    {
        $user = $this->confirmedUser($request);

        Deactivation::deactivate($user, delete: true);

        $result = ['deactivated' => true, 'deletesAt' => $user->deletes_at];

        return (new ActionResource($result))
            ->additional([
                'message' => __('Your account will be deleted on :date. Sign in before then to keep it.', [
                    'date' => $user->deletes_at->toFormattedDateString(),
                ]),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * The signed-in user, once they have confirmed their password.
     */
    protected function confirmedUser(Request $request): User
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User */
        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('That password is not correct.'),
            ]);
        }

        return $user;
    }
}
