<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Helpers\Providers as PV;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Models\PasswordCodeResets;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    /**
     * Handle an incoming new password request.
     *
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'code' => ['required'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // find the code
        $code = PasswordCodeResets::whereToken($request->code)
            ->orWhere('code', $request->code)
            ->first();

        // check if it has not expired: the default time is 30 seconds
        if (! $code || $code->created_at->diffInSeconds(now()) >= PV::config('token_lifespan', 30)) {
            $code && $code->delete();

            throw ValidationException::withMessages([
                'code' => __('The code you provided has expired or does not exist.'),
            ]);
        }

        // find user's email
        $user = User::firstWhere('email', $code->email);

        // Here we will attempt to reset the user's password.
        $user->update(['password' => $request->password]);

        // delete current code
        $code->delete();

        $result = ['changed' => true];

        return (new ActionResource($result))
            ->additional([
                'message' => __('Your password has successfully been changed.'),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Handle an incoming check password request.
     *
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function check(Request $request)
    {
        $this->validate($request, [
            'code' => ['required'],
        ]);

        // find the code
        $code = PasswordCodeResets::firstWhere('code', $request->code);

        // check if it has not expired: the default time is 30 seconds
        if (! $code || $code->created_at->diffInSeconds(now()) >= PV::config('token_lifespan', 30)) {
            $code && $code->delete();

            throw ValidationException::withMessages([
                'code' => __('The code you provided has expired or does not exist.'),
            ]);
        }

        $result = ['valid' => true];

        return (new ActionResource($result))
            ->additional([
                'message' => 'Code verified.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
