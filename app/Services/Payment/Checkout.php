<?php

namespace App\Services\Payment;

use App\Enums\Feature;
use App\Helpers\Providers;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Subscription;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SaleNotification;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Valorin\Random\Random;

/**
 * Turns "I want to pay for X" into a pending transaction, and a confirmed
 * payment into what was paid for (an unlocked release or stems, a tip, a plan,
 * AI credits).
 */
class Checkout
{
    public const PURPOSES = ['premium', 'release', 'stems', 'tip', 'credits'];

    public function __construct(protected PaymentGateway $gateway) {}

    /**
     * Create a pending transaction and start the payment with the gateway.
     *
     * @param  array{id?: string|int|null, plan?: ?string, cycles?: int|string|null, amount?: float|int|string|null, message?: ?string, credits?: int|string|null}  $input
     * @return array{transaction: Transaction, access_code: ?string, authorization_url: ?string}
     */
    public function start(User $payer, string $purpose, array $input): array
    {
        [$amount, $item, $recipient, $meta] = match ($purpose) {
            'release' => $this->forRelease($payer, $input['id'] ?? null),
            'stems' => $this->forStems($payer, $input['id'] ?? null),
            'tip' => $this->forTip($payer, $input['id'] ?? null, $input['amount'] ?? null, $input['message'] ?? null),
            'premium' => $this->forPremium($payer, $input['plan'] ?? null, $input['cycles'] ?? null),
            'credits' => $this->forCredits($payer, $input['credits'] ?? null),
            default => throw ValidationException::withMessages(['purpose' => 'Unknown payment type.']),
        };

        $reference = Providers::config('reference_prefix', 'TRX-').Random::string(20, false, true, true, false);

        // Start with the gateway first so a failed start leaves no pending transaction behind.
        $session = $this->gateway->initialize($payer->email, $amount, $reference, [
            'purpose' => $purpose,
            'user_id' => $payer->id,
        ]);

        $transaction = new Transaction([
            'reference' => $reference,
            'purpose' => $purpose,
            'method' => 'paystack',
            'status' => 'pending',
            'amount' => $amount,
            'discount' => 0,
            'fees' => 0,
            'due' => $amount,
            'recipient_id' => $recipient?->id,
            'meta' => $meta,
        ]);
        $transaction->user_id = $payer->id;
        $transaction->transactable()->associate($item);
        $transaction->save();

        return [
            'transaction' => $transaction,
            'access_code' => $session['access_code'] ?? null,
            'authorization_url' => $session['authorization_url'] ?? null,
        ];
    }

    /**
     * Ask the gateway about a payment and fulfil it if it went through.
     */
    public function verify(Transaction $transaction): Transaction
    {
        if ($transaction->status !== 'pending') {
            return $transaction;
        }

        $result = $this->gateway->verify($transaction->reference);

        if (! $result['paid'] && ! ($result['final'] ?? true)) {
            return $transaction;
        }

        return $this->settle($transaction, $result['paid'], $result['amount'], $result['raw']);
    }

    /**
     * Record the gateway's answer. Safe to call twice for the same payment.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settle(Transaction $transaction, bool $paid, float $amountPaid, array $raw = []): Transaction
    {
        return DB::transaction(function () use ($transaction, $paid, $amountPaid, $raw) {
            /** @var Transaction $locked */
            $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return $locked;
            }

            $locked->webhook = $raw;

            // Paying less than was due (e.g. a tampered amount) doesn't count.
            if (! $paid || $amountPaid + 0.001 < $locked->due) {
                $locked->status = 'rejected';
                $locked->save();

                return $locked;
            }

            $locked->status = 'complete';
            $locked->paid_at = now();

            if ($locked->recipient_id) {
                $share = (float) Providers::config('creator_share', 85);
                $locked->recipient_share = round($locked->amount * $share / 100, 2);
            }

            $locked->save();

            if ($locked->purpose === 'premium') {
                $this->extendPremium($locked);
            }

            if ($locked->purpose === 'credits') {
                Credits::for($locked->user)->add((int) $locked->meta['credits'], (string) $locked->meta['title'], $locked);
            }

            if ($locked->recipient_id) {
                User::query()->find($locked->recipient_id)?->notify(new SaleNotification($locked));
            }

            return $locked;
        });
    }

    /**
     * Add the paid period after any time the user already has left.
     */
    protected function extendPremium(Transaction $transaction): Subscription
    {
        /** @var User $user */
        $user = $transaction->user;

        $plan = Plan::find($transaction->meta['plan_id'] ?? null)
            ?? Plan::where('slug', $transaction->meta['plan'] ?? null)->first();

        $current = $user->subscriptions()->active()->first();
        $starts = $current?->ends_at ?? now();

        return $user->subscriptions()->create([
            'plan' => $plan->slug ?? ($transaction->meta['plan'] ?? 'premium'),
            'plan_id' => $plan?->id,
            'amount' => $transaction->amount,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->add($plan?->period((int) ($transaction->meta['cycles'] ?? 1)) ?? '1 month'),
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * @return array{0: float, 1: Model, 2: ?User, 3: array<string, mixed>}
     */
    protected function forRelease(User $payer, string|int|null $id): array
    {
        /** @var Release $release */
        $release = Release::where('draft', false)
            ->where(fn (Builder $q) => $q->where('slug', (string) $id)->when(is_numeric($id), fn ($q) => $q->orWhere('releases.id', $id)))
            ->firstOrFail();

        if ($release->price <= 0) {
            throw ValidationException::withMessages(['id' => 'This release is free.']);
        }

        if ($release->isUnlockedFor($payer)) {
            throw ValidationException::withMessages(['id' => 'You already own this release.']);
        }

        return [$release->price, $release, $release->user, ['title' => $release->title]];
    }

    /**
     * @return array{0: float, 1: Model, 2: ?User, 3: array<string, mixed>}
     */
    protected function forStems(User $payer, string|int|null $id): array
    {
        /** @var Track $track */
        $track = Track::whereHas('release', fn (Builder $q) => $q->where('draft', false))
            ->where(fn (Builder $q) => $q->where('slug', (string) $id)->when(is_numeric($id), fn ($q) => $q->orWhere('tracks.id', $id)))
            ->firstOrFail();

        if (! $track->stemsForSale()) {
            throw ValidationException::withMessages(['id' => 'The stems for this track are not for sale.']);
        }

        if ($track->stemsUnlockedFor($payer)) {
            throw ValidationException::withMessages(['id' => 'You already have these stems.']);
        }

        return [$track->stems_price, $track, $track->release->user, ['title' => $track->title]];
    }

    /**
     * @return array{0: float, 1: Model, 2: ?User, 3: array<string, mixed>}
     */
    protected function forTip(User $payer, string|int|null $id, mixed $amount, ?string $message): array
    {
        /** @var User $creator */
        $creator = User::where(fn (Builder $q) => $q->where('username', (string) $id)->when(is_numeric($id), fn ($q) => $q->orWhere('id', $id)))
            ->firstOrFail();

        if (! $creator->feature(Feature::RECEIVE_TIPS)) {
            throw ValidationException::withMessages(['id' => 'This creator isn\'t accepting tips.']);
        }

        if ($creator->is($payer)) {
            throw ValidationException::withMessages(['id' => 'You can\'t tip yourself.']);
        }

        $min = (float) Providers::config('min_tip_amount', 100);

        if (! is_numeric($amount) || (float) $amount < $min) {
            throw ValidationException::withMessages(['amount' => "The smallest tip is {$min}."]);
        }

        return [round((float) $amount, 2), $creator, $creator, ['message' => $message ? str($message)->limit(280)->toString() : null]];
    }

    /**
     * @return array{0: float, 1: Model, 2: ?User, 3: array<string, mixed>}
     */
    protected function forPremium(User $payer, string|int|null $plan, int|string|null $cycles = null): array
    {
        /** @var Plan|null $model */
        $model = Plan::active()
            ->where('is_default', false)
            ->where(fn (Builder $q) => $q->where('slug', (string) $plan)->when(is_numeric($plan), fn ($q) => $q->orWhere('id', $plan)))
            ->first();

        if (! $model) {
            throw ValidationException::withMessages(['plan' => 'Choose a plan.']);
        }

        if ($model->isFree()) {
            throw ValidationException::withMessages(['plan' => 'This plan is free.']);
        }

        $option = $model->billingOption((int) ($cycles ?: 1));

        if (! $option) {
            throw ValidationException::withMessages(['cycles' => 'This plan can\'t be paid for that many '.str($model->interval)->plural().' at once.']);
        }

        return [$option['price'], $payer, null, [
            'plan' => $model->slug,
            'plan_id' => $model->id,
            'cycles' => $option['cycles'],
            'discount' => $option['discount'],
            'title' => $option['cycles'] > 1 ? "{$model->name} ({$model->period($option['cycles'])}s)" : $model->name,
        ]];
    }

    /**
     * @return array{0: float, 1: Model, 2: ?User, 3: array<string, mixed>}
     */
    protected function forCredits(User $payer, int|string|null $credits): array
    {
        Credits::for($payer)->ensureAccess('credits');

        $pack = AiSettings::pack((int) $credits);

        if (! $pack || $pack['price'] <= 0) {
            throw ValidationException::withMessages(['credits' => 'Choose a credit pack.']);
        }

        return [$pack['price'], $payer, null, [
            'credits' => $pack['credits'],
            'discount' => $pack['discount'],
            'title' => "{$pack['credits']} AI credits",
        ]];
    }
}
