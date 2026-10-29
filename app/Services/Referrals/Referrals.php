<?php

namespace App\Services\Referrals;

use App\Helpers\Providers;
use App\Models\RoyaltyInvite;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SaleNotification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invite codes, who invited whom, and what inviters earn from the payments of people they invited.
 */
class Referrals
{
    public const PURPOSE = 'referral';

    public const CODE_RULES = ['string', 'min:4', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'];

    /**
     * The user's invite code, made from their username the first time it is asked for.
     */
    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        $base = Str::of($user->username ?: 'invite')
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]/', '')
            ->limit(24, '')
            ->padRight(4, '0')
            ->toString();
        $code = $base;

        while ($this->taken($code, $user)) {
            $code = $base . '-' . Str::lower(Str::random(4));
        }

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * Give the user a code they chose.
     */
    public function setCode(User $user, string $code): string
    {
        $code = Str::lower(trim($code));

        if ($this->taken($code, $user)) {
            throw ValidationException::withMessages(['code' => 'That code is taken.']);
        }

        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /**
     * The user who owns an invite code.
     */
    public function findReferrer(?string $code): ?User
    {
        if (! $code || trim($code) === '') {
            return null;
        }

        return User::query()
            ->where('referral_code', Str::lower(trim($code)))
            ->first();
    }

    /**
     * Record that $referrer invited $invitee. Set once, at sign-up.
     */
    public function attach(User $invitee, User $referrer): void
    {
        if ($invitee->referred_by_id || $invitee->is($referrer)) {
            return;
        }

        $invitee->forceFill([
            'referred_by_id' => $referrer->id,
            'referred_at' => now(),
        ])->save();
    }

    /**
     * The terms the inviter earns on.
     */
    public function terms(User $referrer): ReferralTerms
    {
        $contract = $referrer->currentRoyaltyContract();

        if ($contract) {
            return new ReferralTerms('royalty', $contract->rate, $contract->months, $contract->id);
        }

        return new ReferralTerms(
            'loyalty',
            max(0, min(100, (float) Providers::config('loyalty_rate', 10))),
            max(0, (int) Providers::config('loyalty_months', 12)),
        );
    }

    /**
     * Pay the inviter their part of a completed payment. Safe to call twice for one payment.
     */
    public function reward(Transaction $payment): ?Transaction
    {
        $payer = $payment->user;
        $referrer = $payer?->referredBy;

        if ($payment->status !== 'complete' || ! $referrer || ! $payer->referred_at) {
            return null;
        }

        $terms = $this->terms($referrer);
        $platformShare = (float) $payment->amount - (float) ($payment->recipient_share ?? 0);
        $earned = round($platformShare * $terms->rate / 100, 2);

        if ($earned <= 0 || ! $terms->covers($payer->referred_at, $payment->paid_at ?? now())) {
            return null;
        }

        $existing = Transaction::query()
            ->where('purpose', self::PURPOSE)
            ->whereMorphedTo('transactable', $payment)
            ->first();

        if ($existing) {
            return $existing;
        }

        $reward = new Transaction([
            'reference' => 'ref_' . Str::lower(Str::random(24)),
            'purpose' => self::PURPOSE,
            'method' => $terms->program,
            'status' => 'complete',
            'amount' => 0,
            'due' => 0,
            'recipient_id' => $referrer->id,
            'recipient_share' => $earned,
            'meta' => [
                ...$terms->toArray(),
                'invitee_id' => $payer->id,
                'payment_purpose' => $payment->purpose,
            ],
            'paid_at' => $payment->paid_at ?? now(),
        ]);
        $reward
            ->transactable()
            ->associate($payment);
        $reward->save();

        $referrer->notify(new SaleNotification($reward));

        return $reward;
    }

    /**
     * The user's code, terms, and how many people they invited and what that earned.
     *
     * @return array{code: string, terms: array<string, mixed>, invited: int, earned: float, currency: string}
     */
    public function summary(User $user): array
    {
        $earned = $user->earnings()
            ->where('purpose', self::PURPOSE)
            ->sum('recipient_share');

        return [
            'code' => $this->codeFor($user),
            'terms' => $this->terms($user)->toArray(),
            'invited' => $user->referrals()->count(),
            'earned' => round((float) $earned, 2),
            'currency' => (string) Providers::config('app_currency', 'NGN'),
        ];
    }

    /**
     * Whether another user, or an open royalty invite, holds the code.
     */
    protected function taken(string $code, User $user): bool
    {
        $byUser = User::query()
            ->where('referral_code', $code)
            ->whereKeyNot($user->id)
            ->exists();

        return $byUser || RoyaltyInvite::query()
            ->open()
            ->where('code', $code)
            ->where(fn($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->exists();
    }
}
