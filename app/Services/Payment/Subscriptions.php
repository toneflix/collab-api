<?php

namespace App\Services\Payment;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The periods a user spends on a paid plan, whether paid for or given by an admin.
 */
class Subscriptions
{
    /**
     * Add a period after any time the user already has paid for.
     */
    public function extend(User $user, ?Plan $plan, int $cycles = 1, float $amount = 0, ?Transaction $transaction = null, ?string $slug = null): Subscription
    {
        $paidUntil = Subscription::query()
            ->where('user_id', $user->id)
            ->where('ends_at', '>', now())
            ->max('ends_at');
        $starts = $paidUntil ? Carbon::parse($paidUntil) : now();

        return $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug ?? $slug ?? 'premium',
                'plan_id' => $plan?->id,
                'amount' => $amount,
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->add($plan?->period($cycles) ?? '1 month'),
                'transaction_id' => $transaction?->id,
            ]);
    }

    /**
     * Put the user on a plan. More time on the plan they already have is
     * added after it; a different plan replaces it from now.
     */
    public function assign(User $user, Plan $plan, int $cycles = 1): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $cycles) {
            if ($this->current($user)?->plan_id !== $plan->id) {
                $this->end($user);
            }

            return $this->extend($user, $plan, $cycles);
        });
    }

    /**
     * Stop the current period and any paid in advance, which puts the user back on the default plan.
     */
    public function end(User $user): int
    {
        $now = now();
        $open = fn() => Subscription::query()->where('user_id', $user->id)->where('ends_at', '>', $now);

        $queued = $open()
            ->where('starts_at', '>', $now)
            ->update(['starts_at' => $now, 'ends_at' => $now]);

        return $queued + $open()->update(['ends_at' => $now]);
    }

    public function current(User $user): ?Subscription
    {
        return $user
            ->subscriptions()
            ->active()
            ->with('planModel')
            ->first();
    }
}
