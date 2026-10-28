<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user turning two-factor sign-in on or off, and their
 * recovery codes.
 */
class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactor $twoFactor)
    {
        parent::__construct();
    }

    public function show(Request $request)
    {
        $result = $this->status($request->user());

        return $this->respond($result, HttpStatus::message(HttpStatus::OK), HttpStatus::OK);
    }

    /**
     * Make a new secret to add to an authenticator app.
     */
    public function store(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User */
        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => __('That password is not correct.')]);
        }

        $result = $this->twoFactor->start($user);

        return $this->respond($result, __('Scan the code with your authenticator app, then enter the code it shows.'), HttpStatus::OK);
    }

    public function confirm(Request $request)
    {
        $valid = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $user = $request->user();
        $codes = $this->twoFactor->confirm($user, $valid['code']);
        $result = [...$this->status($user), 'recoveryCodes' => $codes];

        return $this->respond($result, __('Two-factor sign-in is on. Keep your recovery codes somewhere safe.'), HttpStatus::ACCEPTED);
    }

    public function recoveryCodes(Request $request)
    {
        $valid = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $user = $request->user();
        $codes = $this->twoFactor->regenerate($user, $valid['code']);
        $result = [...$this->status($user), 'recoveryCodes' => $codes];

        return $this->respond($result, __('New recovery codes made. The old ones no longer work.'), HttpStatus::ACCEPTED);
    }

    public function destroy(Request $request)
    {
        $valid = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $user = $request->user();
        $this->twoFactor->disable($user, $valid['code']);
        $result = $this->status($user);

        return $this->respond($result, __('Two-factor sign-in is off.'), HttpStatus::ACCEPTED);
    }

    /**
     * @return array{enabled: bool, confirmedAt: mixed, recoveryCodesLeft: int}
     */
    protected function status(User $user): array
    {
        return [
            'enabled' => $this->twoFactor->enabled($user),
            'confirmedAt' => $user->two_factor_confirmed_at,
            'recoveryCodesLeft' => $this->twoFactor->recoveryCodesLeft($user),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function respond(array $result, string $message, HttpStatus $status)
    {
        return (new ActionResource($result))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
