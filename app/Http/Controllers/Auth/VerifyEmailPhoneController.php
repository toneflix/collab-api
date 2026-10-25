<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Events\PhoneVerified;
use App\Helpers\Providers as PV;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VerifyEmailPhoneController extends Controller
{
    /**
     * Send a new email verification notification.
     *
     * @return JsonResponse|RedirectResponse
     */
    public function store(Request $request, $type = 'email')
    {
        /** @var User $user */
        $user = $request->user();

        $set_type = ($type == 'phone') ? 'phone number' : 'email address';
        $hasVerified = ($type == 'phone') ? $user->hasVerifiedPhone() : $user->hasVerifiedEmail();

        if ($hasVerified) {
            return (new UserResource($user))
                ->additional([
                    'reboot' => true,
                    'message' => "Your $set_type is already verified.",
                    'status' => 'info',
                    'status_code' => HttpStatus::OK,
                ])
                ->response()
                ->setStatusCode(HttpStatus::OK->value);
        }

        if ($type === 'email') {
            $user->sendEmailVerificationNotification();
        }

        if ($type === 'phone') {
            $user->sendPhoneVerificationNotification();
        }

        /** @var Carbon */
        $datetime = $user->last_attempt ?? now()->subSeconds(PV::config('token_lifespan', 30) + 1);
        $dateAdd = $datetime->addSeconds(PV::config('token_lifespan', 30));

        return (new UserResource($user))
            ->additional([
                'message' => "Verification code has been sent to your {$set_type}.",
                'time_left' => $dateAdd->shortAbsoluteDiffForHumans(),
                'try_at' => $dateAdd->toDateTimeLocalString(),
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Ping the verification notification to know the status.
     *
     * @return JsonResponse|RedirectResponse
     */
    public function show(Request $request, $type = 'email')
    {
        /** @var User $user */
        $user = $request->user();

        $set_type = ($type == 'phone') ? 'phone number' : 'email address';

        /** @var Carbon */
        $datetime = $user->last_attempt ?? now()->subSeconds(PV::config('token_lifespan', 30) + 1);

        $dateAdd = $datetime->addSeconds(PV::config('token_lifespan', 30));
        $hasVerified = ($type == 'phone') ? $user->hasVerifiedPhone() : $user->hasVerifiedEmail();

        return (new UserResource($user))
            ->additional([
                'reboot' => (bool) $hasVerified,
                'message' => $hasVerified
                    ? "We have successfully verified your $set_type, welcome to our community."
                    : "Your $set_type is not yet verified.",
                'time_left' => $dateAdd->shortAbsoluteDiffForHumans(),
                'try_at' => $dateAdd->toDateTimeLocalString(),
                'verified' => $hasVerified,
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }

    /**
     * Mark the authenticated user's email address as verified.
     *
     * @param  \Illuminate\Foundation\Auth\Request  $request
     * @return Response
     */
    public function update(Request $request, $type = 'email')
    {
        /** @var User $user */
        $user = $request->user();

        $set_type = ($type == 'phone') ? 'phone number' : 'email address';

        $hasVerified = ($type == 'phone')
            ? $user->hasVerifiedPhone()
            : $user->hasVerifiedEmail();

        if ($hasVerified) {
            return (new UserResource($user))
                ->additional([
                    'reboot' => true,
                    'message' => "Your $set_type is already verified.",
                    'status' => 'info',
                    'status_code' => HttpStatus::OK,
                ])
                ->response()
                ->setStatusCode(HttpStatus::OK->value);
        }

        Validator::make($request->all(), [
            'code' => ['required'],
        ])->validate();

        $code = ($type == 'email')
            ? $user->email_verify_code
            : (
                $type == 'phone'
                ? $user->phone_verify_code
                : null
            );

        // check if it has not expired: the time is 30 minutes and that the code is valid
        $last_attempt = ($user->hasVerifiedPhone() && $user->last_attempt === null)
            ? $user->phone_verified_at
            : $user->last_attempt;

        if ($request->code !== $code || $last_attempt->diffInMinutes(now()) >= PV::config('token_lifespan', 30)) {
            throw ValidationException::withMessages([
                'code' => __('The code you provided has expired or does not exist.'),
            ]);
        }

        if ($type == 'email' && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($type == 'phone' && $request->user()->markPhoneAsVerified()) {
            event(new PhoneVerified($user));
        }

        return (new UserResource($user))
            ->additional([
                'reboot' => true,
                'message' => "We have successfully verified your $set_type, welcome to our community.",
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }
}
