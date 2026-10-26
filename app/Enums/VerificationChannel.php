<?php

namespace App\Enums;

use App\Events\PhoneVerified;
use App\Helpers\Providers;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * The contact details a user can confirm with a one-time code.
 */
enum VerificationChannel: string
{
    case EMAIL = 'email';
    case PHONE = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'email address',
            self::PHONE => 'phone number',
        };
    }

    /**
     * Whether the app asks users to confirm this channel.
     */
    public function required(): bool
    {
        return (bool) Providers::config("verify_{$this->value}", false);
    }

    public function applies(User $user): bool
    {
        return filled($this === self::EMAIL ? $user->email : $user->phone);
    }

    public function verified(User $user): bool
    {
        return $this === self::EMAIL
            ? $user->hasVerifiedEmail()
            : $user->hasVerifiedPhone();
    }

    public function code(User $user): ?string
    {
        return $this === self::EMAIL
            ? $user->email_verify_code
            : $user->phone_verify_code;
    }

    public function send(User $user): void
    {
        $this === self::EMAIL
            ? $user->sendEmailVerificationNotification()
            : $user->sendPhoneVerificationNotification();
    }

    public function markVerified(User $user): void
    {
        $changed = $this === self::EMAIL
            ? $user->markEmailAsVerified()
            : $user->markPhoneAsVerified();

        if ($changed) {
            event($this === self::EMAIL ? new Verified($user) : new PhoneVerified($user));
        }
    }

    /**
     * Whether the user still has to confirm this channel before using the app.
     */
    public function pending(User $user): bool
    {
        return $this->required() && $this->applies($user) && ! $this->verified($user);
    }
}
