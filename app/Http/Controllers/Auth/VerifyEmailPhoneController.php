<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Enums\VerificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\CodeClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a signed-in user's email address or phone number with a one-time code.
 */
class VerifyEmailPhoneController extends Controller
{
    /**
     * Send a new code.
     */
    public function store(Request $request, string $type = 'email'): JsonResponse
    {
        $channel = $this->channel($type);

        /** @var User $user */
        $user = $request->user();

        if ($channel->verified($user)) {
            return $this->respond($user, "Your {$channel->label()} is already verified.");
        }

        abort_unless(
            $channel->applies($user),
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            "Add a {$channel->label()} to your account first.",
        );

        $channel->send($user);

        return $this->respond($user, "We sent a code to your {$channel->label()}.", HttpStatus::CREATED);
    }

    /**
     * Report whether the channel is verified and when a new code can be sent.
     */
    public function show(Request $request, string $type = 'email'): JsonResponse
    {
        $channel = $this->channel($type);

        /** @var User $user */
        $user = $request->user();

        $message = $channel->verified($user)
            ? "Your {$channel->label()} is verified."
            : "Your {$channel->label()} is not verified yet.";

        return $this->respond($user, $message);
    }

    /**
     * Check the code and mark the channel verified.
     */
    public function update(Request $request, string $type = 'email'): JsonResponse
    {
        $channel = $this->channel($type);

        /** @var User $user */
        $user = $request->user();

        if ($channel->verified($user)) {
            return $this->respond($user, "Your {$channel->label()} is already verified.");
        }

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $code = $channel->code($user);

        if (! $code || ! hash_equals($code, (string) $request->input('code')) || CodeClock::expired($user->last_attempt)) {
            throw ValidationException::withMessages([
                'code' => __('This code has expired or is not valid.'),
            ]);
        }

        $channel->markVerified($user);

        return $this->respond($user, "Your {$channel->label()} is verified.", HttpStatus::ACCEPTED);
    }

    protected function channel(string $type): VerificationChannel
    {
        $channel = VerificationChannel::tryFrom($type);

        abort_unless($channel, HttpStatus::NOT_FOUND->value, 'Unknown verification type.');

        return $channel;
    }

    protected function respond(User $user, string $message, HttpStatus $status = HttpStatus::OK): JsonResponse
    {
        $user->refresh();

        $verified = collect(VerificationChannel::cases())
            ->mapWithKeys(fn(VerificationChannel $channel) => [$channel->value => $channel->verified($user)])
            ->all();

        return (new UserResource($user))
            ->additional([
                ...CodeClock::retry($user->last_attempt),
                'verified' => $verified,
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
