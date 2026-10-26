<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Models\PasswordCodeResets;
use App\Models\User;
use App\Services\Auth\CodeClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Sets a new password with the code or link sent by PasswordResetLinkController.
 */
class NewPasswordController extends Controller
{
    /**
     * Set the new password.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $reset = $this->findReset($request->input('code'));

        $user = User::query()
            ->where('email', $reset->email)
            ->firstOrFail();

        $user->password = $request->input('password');

        // The code reached the user's inbox, so the address is theirs.
        $user->email_verified_at ??= now();
        $user->save();

        PasswordCodeResets::whereEmail($reset->email)
            ->delete();

        $result = ['changed' => true];

        return (new ActionResource($result))
            ->additional([
                'message' => __('Your password has been changed.'),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Check a code or link token before asking for the new password.
     *
     * @throws ValidationException
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $reset = $this->findReset($request->input('code'));

        $result = ['valid' => true, 'email' => $reset->email];

        return (new ActionResource($result))
            ->additional([
                'message' => 'Code accepted.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Find a live reset by its six-digit code or the token in the emailed link.
     *
     * @throws ValidationException
     */
    protected function findReset(string $code): PasswordCodeResets
    {
        $reset = PasswordCodeResets::query()
            ->where(
                fn(Builder $query) => $query
                    ->where('token', $code)
                    ->orWhere('code', $code),
            )
            ->latest('id')
            ->first();

        if (! $reset || CodeClock::expired($reset->created_at)) {
            $reset?->delete();

            throw ValidationException::withMessages([
                'code' => __('This code has expired or is not valid.'),
            ]);
        }

        return $reset;
    }
}
