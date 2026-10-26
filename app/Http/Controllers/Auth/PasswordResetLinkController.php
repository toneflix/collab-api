<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Models\PasswordCodeResets;
use App\Notifications\SendCode;
use App\Services\Auth\CodeClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Valorin\Random\Random;

class PasswordResetLinkController extends Controller
{
    /**
     * Handle an incoming password reset link request.
     *
     * @return JsonResponse
     *
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'email' => ['required', 'email', 'exists:users'],
        ], [
            'email.exists' => 'We could not find a user with this email address.',
        ]);

        PasswordCodeResets::whereEmail($request->email)
            ->delete();

        $reset = new PasswordCodeResets;
        $reset->email = $request->email;
        $reset->code = Random::otp(6);
        $reset->token = Random::string(64, true, true, true, false);
        $reset->save();
        $reset->notify(new SendCode);

        $retry = CodeClock::retry($reset->created_at);

        return (new ActionResource($retry))
            ->additional([
                ...$retry,
                'message' => __('We sent a reset code to your email.'),
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }
}
