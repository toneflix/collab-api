<?php

namespace App\Services\Ai;

use App\Enums\Feature;
use App\Models\AiCreditEntry;
use App\Models\AiWallet;
use App\Models\User;
use App\Services\Limits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A user's AI credits. Their plan gives an amount each calendar month, which
 * is used first and doesn't roll over. Bought credits come on top and never
 * expire.
 */
class Credits
{
    public function __construct(public User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    /**
     * Credits the user's plan gives each month.
     */
    public function allowance(): int
    {
        return Limits::for($this->user)->get(Feature::AI_CREDITS);
    }

    /**
     * @return array{allowance: int, planUsed: int, planLeft: int, purchased: int, total: int, resetsAt: string}
     */
    public function balance(): array
    {
        $wallet = $this->wallet();
        $allowance = $this->allowance();
        $planUsed = $this->planUsed($wallet);
        $planLeft = max(0, $allowance - $planUsed);

        return [
            'allowance' => $allowance,
            'planUsed' => min($planUsed, $allowance),
            'planLeft' => $planLeft,
            'purchased' => $wallet->purchased,
            'total' => $planLeft + $wallet->purchased,
            'resetsAt' => $this->period()->addMonth()->toIso8601String(),
        ];
    }

    public function total(): int
    {
        return $this->balance()['total'];
    }

    /**
     * Stop when the user's plan doesn't include AI tools or they're switched off.
     */
    public function ensureAccess(string $field = 'ai'): void
    {
        if (! AiSettings::enabled()) {
            throw ValidationException::withMessages([$field => 'AI tools are switched off right now.']);
        }

        Limits::for($this->user)->ensureAllowed(Feature::AI_TOOLS, $field);
    }

    /**
     * Take credits for some work, plan credits first. Stops when there aren't enough.
     */
    public function spend(int $credits, string $description, ?Model $source = null, string $field = 'credits'): AiCreditEntry
    {
        $credits = max(1, $credits);

        return DB::transaction(function () use ($credits, $description, $source, $field) {
            $wallet = $this->lockedWallet();
            $planLeft = max(0, $this->allowance() - $this->planUsed($wallet));

            if ($planLeft + $wallet->purchased < $credits) {
                throw ValidationException::withMessages([
                    $field => __('This needs :need credits, but you have :have.', ['need' => $credits, 'have' => $planLeft + $wallet->purchased]),
                ]);
            }

            $fromPlan = min($planLeft, $credits);
            $fromPurchased = $credits - $fromPlan;

            $wallet->forceFill([
                'period' => $this->period(),
                'plan_used' => $this->planUsed($wallet) + $fromPlan,
                'purchased' => $wallet->purchased - $fromPurchased,
            ])->save();

            return $this->record(AiCreditEntry::SPEND, -$credits, $description, $source, $fromPlan, $fromPurchased);
        });
    }

    /**
     * Put back the credits of a spend whose work failed. Plan credits only come
     * back within the same month. Safe to call twice.
     */
    public function refund(AiCreditEntry $spend, string $description = 'Refund'): ?AiCreditEntry
    {
        return DB::transaction(function () use ($spend, $description) {
            $wallet = $this->lockedWallet();

            /** @var AiCreditEntry $locked */
            $locked = AiCreditEntry::query()->whereKey($spend->id)->lockForUpdate()->firstOrFail();

            if ($locked->type !== AiCreditEntry::SPEND || $locked->refund()->exists()) {
                return null;
            }

            $samePeriod = $locked->created_at && $locked->created_at->gte($this->period());
            $toPlan = $samePeriod ? min($locked->plan_credits, $this->planUsed($wallet)) : 0;
            $toPurchased = $locked->purchased_credits;

            $wallet->forceFill([
                'period' => $this->period(),
                'plan_used' => $this->planUsed($wallet) - $toPlan,
                'purchased' => $wallet->purchased + $toPurchased,
            ])->save();

            return $this->record(AiCreditEntry::REFUND, $toPlan + $toPurchased, $description, $locked->source, $toPlan, $toPurchased, $locked->id);
        });
    }

    /**
     * Add bought or granted credits.
     */
    public function add(int $credits, string $description, ?Model $source = null, string $type = AiCreditEntry::PURCHASE): AiCreditEntry
    {
        return DB::transaction(function () use ($credits, $description, $source, $type) {
            $wallet = $this->lockedWallet();
            $wallet->increment('purchased', max(0, $credits));

            return $this->record($type, max(0, $credits), $description, $source, 0, max(0, $credits));
        });
    }

    /**
     * Take away bought or granted credits. Plan credits aren't touched, and
     * taking more than the user has is refused.
     */
    public function deduct(int $credits, string $description, string $field = 'credits'): AiCreditEntry
    {
        $credits = max(1, $credits);

        return DB::transaction(function () use ($credits, $description, $field) {
            $wallet = $this->lockedWallet();

            if ($wallet->purchased < $credits) {
                throw ValidationException::withMessages([
                    $field => __('Only :have bought or granted credits can be taken away.', ['have' => $wallet->purchased]),
                ]);
            }

            $wallet->decrement('purchased', $credits);

            return $this->record(AiCreditEntry::DEDUCT, -$credits, $description, null, 0, $credits);
        });
    }

    /**
     * The calendar month plan credits are counted in.
     */
    public function period(): Carbon
    {
        return now()->startOfMonth();
    }

    protected function planUsed(AiWallet $wallet): int
    {
        return $wallet->period && $wallet->period->gte($this->period()) ? $wallet->plan_used : 0;
    }

    protected function wallet(): AiWallet
    {
        return AiWallet::query()->firstOrCreate(['user_id' => $this->user->id]);
    }

    protected function lockedWallet(): AiWallet
    {
        $this->wallet();

        return AiWallet::query()->where('user_id', $this->user->id)->lockForUpdate()->firstOrFail();
    }

    protected function record(string $type, int $credits, string $description, ?Model $source, int $plan, int $purchased, ?int $refundOf = null): AiCreditEntry
    {
        $entry = new AiCreditEntry([
            'user_id' => $this->user->id,
            'type' => $type,
            'credits' => $credits,
            'plan_credits' => $plan,
            'purchased_credits' => $purchased,
            'description' => str($description)->limit(250)->toString(),
            'refund_of' => $refundOf,
        ]);

        if ($source) {
            $entry->source()->associate($source);
        }

        $entry->save();

        return $entry;
    }
}
