<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Models\PasswordCodeResets;
use App\Notifications\SendCode;
use Carbon\Carbon;
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

        // Delete the old code
        PasswordCodeResets::whereEmail($request->email)->delete();

        // // generate the new code
        $reset = new PasswordCodeResets;
        $reset->email = $request->email;
        $reset->code = Random::otp(6);
        $reset->token = Random::string(64, true, true, true, false);
        $reset->save();
        $reset->notify(new SendCode);

        /** @var Carbon */
        $dateAdd = $reset->created_at->addSeconds(config('settings.token_lifespan', 30));

        // And finally return a response
        $retry = [
            'try_at' => $dateAdd->toDateTimeLocalString(),
            'time_left' => $dateAdd->shortAbsoluteDiffForHumans(),
        ];

        return (new ActionResource($retry))
            ->additional([
                ...$retry,
                'message' => __('We have sent you a message to help with recovering your password.'),
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }
}
