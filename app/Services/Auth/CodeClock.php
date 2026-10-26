<?php

namespace App\Services\Auth;

use App\Helpers\Providers;
use Carbon\CarbonInterface;

/**
 * Times the one-time codes sent for password resets and email or phone verification.
 *
 * A code lives for the `token_lifespan` setting (seconds); another can be requested after RESEND_AFTER seconds.
 */
class CodeClock
{
    public const RESEND_AFTER = 60;

    public static function lifespan(): int
    {
        return max(60, (int) Providers::config('token_lifespan', 300));
    }

    public static function expiresAt(CarbonInterface $sentAt): CarbonInterface
    {
        return $sentAt
            ->copy()
            ->addSeconds(self::lifespan());
    }

    public static function expired(?CarbonInterface $sentAt): bool
    {
        return ! $sentAt || self::expiresAt($sentAt)->isPast();
    }

    public static function resendAt(?CarbonInterface $sentAt): CarbonInterface
    {
        return $sentAt
            ? $sentAt->copy()->addSeconds(self::RESEND_AFTER)
            : now();
    }

    /**
     * When the next code can be requested, as sent to the app.
     *
     * @return array{try_at: string, time_left: string}
     */
    public static function retry(?CarbonInterface $sentAt): array
    {
        $at = self::resendAt($sentAt);

        return [
            'try_at' => $at->toIso8601String(),
            'time_left' => $at->shortAbsoluteDiffForHumans(),
        ];
    }
}
