<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor sign-in with an authenticator app, its recovery codes, and the
 * code check that sensitive actions ask for.
 */
class TwoFactor
{
    /** Recovery codes made at a time */
    public const RECOVERY_CODES = 8;

    /** Wrong codes allowed a minute */
    public const ATTEMPTS = 5;

    /** Minutes a sign-in challenge stays open */
    public const CHALLENGE_MINUTES = 10;

    public function enabled(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /**
     * Make a new secret for the user to add to their authenticator app. It
     * takes effect once they confirm a code from it.
     *
     * @return array{secret: string, uri: string}
     */
    public function start(User $user): array
    {
        if ($this->enabled($user)) {
            throw ValidationException::withMessages(['code' => __('Two-factor sign-in is already on.')]);
        }

        $secret = Totp::secret();

        $user
            ->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_step' => null,
            ])
            ->save();

        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, (string) ($user->email ?: $user->username), (string) config('app.name')),
        ];
    }

    /**
     * Turn two-factor sign-in on with a code from the new secret.
     *
     * @return list<string> the recovery codes, shown once
     */
    public function confirm(User $user, string $code): array
    {
        if ($this->enabled($user) || ! $user->two_factor_secret) {
            throw ValidationException::withMessages(['code' => __('Start setting up two-factor sign-in first.')]);
        }

        $this->throttle($user);

        if (! $this->usesCode($user, $code)) {
            $this->failed($user);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()]);

        return $this->newRecoveryCodes($user);
    }

    public function disable(User $user, ?string $code): void
    {
        $this->check($user, $code);
        $this->reset($user);
    }

    /**
     * Clear the secret and recovery codes without asking for a code.
     */
    public function reset(User $user): void
    {
        $user
            ->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_last_step' => null,
            ])
            ->save();
    }

    /**
     * Replace the recovery codes.
     *
     * @return list<string>
     */
    public function regenerate(User $user, ?string $code): array
    {
        $this->check($user, $code);

        return $this->newRecoveryCodes($user);
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Make sure the user has two-factor sign-in on and gave a current code or
     * an unused recovery code. Each code works once.
     *
     * @throws ValidationException
     */
    public function check(User $user, ?string $code, string $field = 'code'): void
    {
        if (! $this->enabled($user)) {
            throw ValidationException::withMessages([
                $field => __('Turn on two-factor sign-in in your account settings first.'),
            ]);
        }

        $this->throttle($user, $field);

        if (! $this->passes($user, (string) $code)) {
            $this->failed($user, $field);
        }

        RateLimiter::clear($this->throttleKey($user));
    }

    /**
     * Open a sign-in challenge for a user who passed their password.
     */
    public function challenge(User $user, bool $remember = false): string
    {
        $token = Str::random(64);

        Cache::put($this->challengeKey($token), [
            'user' => $user->id,
            'remember' => $remember,
        ], now()->addMinutes(self::CHALLENGE_MINUTES));

        return $token;
    }

    /**
     * The user a sign-in challenge was for, once they answer it with a code.
     *
     * @throws ValidationException
     */
    public function answer(string $token, ?string $code): User
    {
        $challenge = Cache::get($this->challengeKey($token));
        $user = $challenge ? User::query()->withoutGlobalScopes()->find($challenge['user']) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'challenge' => __('This sign-in has expired. Enter your email and password again.'),
            ]);
        }

        $this->check($user, $code);

        Cache::forget($this->challengeKey($token));

        return $user;
    }

    /**
     * Whether a code is current for the user's secret, or one of their unused
     * recovery codes. Either is used up when it passes.
     */
    protected function passes(User $user, string $code): bool
    {
        $code = trim($code);

        if ($this->usesCode($user, $code)) {
            return true;
        }

        return $this->usesRecoveryCode($user, $code);
    }

    /**
     * Accept a current code once: a code from a time step already used is
     * turned down.
     */
    protected function usesCode(User $user, string $code): bool
    {
        $step = Totp::match((string) $user->two_factor_secret, preg_replace('/\s+/', '', $code));

        if ($step === null) {
            return false;
        }

        $claimed = User::query()
            ->withoutGlobalScopes()
            ->whereKey($user->id)
            ->where(fn($q) => $q
                ->whereNull('two_factor_last_step')
                ->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]);

        $user->two_factor_last_step = $step;

        return $claimed === 1;
    }

    protected function usesRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hash($code);
        $codes = collect($user->two_factor_recovery_codes ?? []);

        if ($code === '' || ! $codes->contains(fn(string $known) => hash_equals($known, $hash))) {
            return false;
        }

        $user
            ->forceFill([
                'two_factor_recovery_codes' => $codes
                    ->reject(fn(string $known) => hash_equals($known, $hash))
                    ->values()
                    ->all(),
            ])
            ->save();

        return true;
    }

    /**
     * @return list<string>
     */
    protected function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn() => Str::lower(Str::random(5) . '-' . Str::random(5)))
            ->all();

        $user
            ->forceFill(['two_factor_recovery_codes' => array_map($this->hash(...), $codes)])
            ->save();

        return $codes;
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', Str::lower(trim($code)), (string) config('app.key'));
    }

    protected function throttle(User $user, string $field = 'code'): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($user), self::ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('Too many wrong codes. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($this->throttleKey($user)),
            ]),
        ]);
    }

    protected function failed(User $user, string $field = 'code'): never
    {
        RateLimiter::hit($this->throttleKey($user));

        throw ValidationException::withMessages([$field => __('That code is not correct.')]);
    }

    protected function throttleKey(User $user): string
    {
        return 'two-factor:' . $user->id;
    }

    protected function challengeKey(string $token): string
    {
        return 'two-factor-challenge:' . hash('sha256', $token);
    }
}
