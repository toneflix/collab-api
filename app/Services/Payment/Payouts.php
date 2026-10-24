<?php

namespace App\Services\Payment;

use App\Broadcasting\Channels;
use App\Enums\PayoutStatus;
use App\Helpers\Providers;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Realtime\Realtime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Valorin\Random\Random;

/**
 * Creators' withdrawable balance, the bank account they withdraw to, and the
 * life of each withdrawal from request to the money landing in their bank.
 */
class Payouts
{
    public function __construct(protected PayoutGateway $gateway) {}

    public function currency(): string
    {
        return (string) Providers::config('app_currency', 'NGN');
    }

    public function minimum(): float
    {
        return (float) Providers::config('min_payout_amount', 1000);
    }

    public function holdDays(): int
    {
        return max(0, (int) Providers::config('payout_hold_days', 7));
    }

    /**
     * What the creator has earned, what has cleared, and what they can withdraw now.
     *
     * @return array{earned: float, clearing: float, withdrawn: float, inFlight: float, available: float, minimum: float, holdDays: int, currency: string}
     */
    public function summary(User $user): array
    {
        $cutoff = now()->subDays($this->holdDays());

        $earnings = $user->earnings()
            ->toBase()
            ->selectRaw('coalesce(sum(recipient_share), 0) as earned')
            ->selectRaw('coalesce(sum(case when paid_at <= ? then recipient_share else 0 end), 0) as cleared', [$cutoff])
            ->first();

        $payouts = $user->payouts()
            ->toBase()
            ->selectRaw('coalesce(sum(case when status = ? then amount else 0 end), 0) as withdrawn', [PayoutStatus::PAID->value])
            ->selectRaw('coalesce(sum(case when status in (?, ?) then amount else 0 end), 0) as in_flight', [
                PayoutStatus::PENDING->value,
                PayoutStatus::PROCESSING->value,
            ])
            ->first();

        $withdrawn = (float) $payouts->withdrawn;
        $inFlight = (float) $payouts->in_flight;

        return [
            'earned' => (float) $earnings->earned,
            'clearing' => round((float) $earnings->earned - (float) $earnings->cleared, 2),
            'withdrawn' => $withdrawn,
            'inFlight' => $inFlight,
            'available' => max(0, round((float) $earnings->cleared - $withdrawn - $inFlight, 2)),
            'minimum' => $this->minimum(),
            'holdDays' => $this->holdDays(),
            'currency' => $this->currency(),
        ];
    }

    /**
     * Banks creators can withdraw to.
     *
     * @return array<int, array{name: string, code: string}>
     */
    public function banks(): array
    {
        $currency = $this->currency();
        $key = "payouts:banks:{$currency}";

        if ($banks = Cache::get($key)) {
            return $banks;
        }

        $banks = $this->gateway->banks($currency);

        if ($banks) {
            Cache::put($key, $banks, now()->addDay());
        }

        return $banks;
    }

    /**
     * Look up the name on an account so the creator can confirm it before saving.
     *
     * @return array{account_name: string, account_number: string, bank_code: string, bank_name: string}
     */
    public function resolve(string $bankCode, string $accountNumber): array
    {
        $bank = collect($this->banks())->firstWhere('code', $bankCode);

        if (! $bank) {
            throw ValidationException::withMessages(['bank_code' => 'Choose a bank from the list.']);
        }

        return [
            'account_name' => $this->gateway->resolveAccount($accountNumber, $bankCode),
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => $bank['name'],
        ];
    }

    /**
     * Save the account the creator gets paid into, registered with the provider.
     */
    public function saveAccount(User $user, string $bankCode, string $accountNumber): PayoutAccount
    {
        $details = $this->resolve($bankCode, $accountNumber);
        $current = $user->payoutAccount()->first();

        $unchanged = $current
            && $current->recipient_code
            && $current->bank_code === $bankCode
            && $current->account_number === $accountNumber;

        $recipient = $unchanged
            ? $current->recipient_code
            : $this->gateway->createRecipient($details['account_name'], $accountNumber, $bankCode, $this->currency());

        return $user->payoutAccount()->updateOrCreate([], [
            ...$details,
            'currency' => $this->currency(),
            'recipient_code' => $recipient,
        ]);
    }

    /**
     * Ask to withdraw part of the available balance.
     */
    public function request(User $user, float $amount): Payout
    {
        $amount = round($amount, 2);

        $payout = DB::transaction(function () use ($user, $amount) {
            // One request at a time per creator, so two at once can't both spend the same balance
            User::whereKey($user->id)->lockForUpdate()->first();

            $account = $user->payoutAccount()->first();

            if (! $account?->recipient_code) {
                throw ValidationException::withMessages(['account' => 'Add the bank account to pay into first.']);
            }

            if ($amount < $this->minimum()) {
                throw ValidationException::withMessages(['amount' => 'The smallest withdrawal is '.$this->minimum().'.']);
            }

            if ($amount > $this->summary($user)['available']) {
                throw ValidationException::withMessages(['amount' => 'That is more than your available balance.']);
            }

            return $user->payouts()->create([
                'reference' => 'po_'.Random::string(24, true, false, true, false),
                'amount' => $amount,
                'currency' => $this->currency(),
                'status' => PayoutStatus::PENDING,
                'destination' => $account->snapshot(),
            ]);
        });

        if (Providers::config('payout_auto_approve', false)) {
            return $this->send($payout);
        }

        return $payout;
    }

    /**
     * The creator takes back a request no one has acted on yet.
     */
    public function cancel(Payout $payout): Payout
    {
        return $this->review($payout, PayoutStatus::CANCELLED, null, 'Only withdrawals waiting for approval can be cancelled.');
    }

    /**
     * An admin turns a request down, releasing the amount back to the balance.
     */
    public function reject(Payout $payout, User $admin, ?string $reason = null): Payout
    {
        return $this->review($payout, PayoutStatus::REJECTED, $admin, 'This withdrawal has already been handled.', $reason);
    }

    /**
     * An admin approves a request, which sends it to the provider.
     */
    public function approve(Payout $payout, User $admin): Payout
    {
        return $this->send($payout, $admin);
    }

    /**
     * Hand a pending request to the provider.
     */
    public function send(Payout $payout, ?User $admin = null): Payout
    {
        $payout = $this->review($payout, PayoutStatus::PROCESSING, $admin, 'This withdrawal has already been handled.');

        return $this->transfer($payout);
    }

    /**
     * Ask the provider what happened to a payout that is on its way, or a paid one that may
     * have been reversed. Safe to call any number of times.
     */
    public function sync(Payout $payout): Payout
    {
        if (! in_array($payout->status, [PayoutStatus::PROCESSING, PayoutStatus::PAID], true)) {
            return $payout;
        }

        $result = $this->gateway->verifyTransfer($payout->reference);

        // The first attempt never reached the provider. The reference stays the same, so this can't pay twice.
        if ($result['status'] === 'missing') {
            return $payout->status === PayoutStatus::PROCESSING ? $this->transfer($payout) : $payout;
        }

        return $this->settle($payout, $result);
    }

    /**
     * Move a payout out of pending, refusing if someone else already did.
     */
    protected function review(Payout $payout, PayoutStatus $status, ?User $admin, string $refusal, ?string $reason = null): Payout
    {
        $payout = DB::transaction(function () use ($payout, $status, $admin, $refusal, $reason) {
            /** @var Payout $locked */
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayoutStatus::PENDING) {
                throw ValidationException::withMessages(['payout' => $refusal]);
            }

            $locked->status = $status;
            $locked->failure_reason = $reason;

            if ($admin) {
                $locked->reviewed_by = $admin->id;
                $locked->reviewed_at = now();
            }

            $locked->save();

            return $locked;
        });

        $this->announce($payout);

        return $payout;
    }

    protected function transfer(Payout $payout): Payout
    {
        try {
            $result = $this->gateway->transfer(
                $payout->amount,
                $payout->currency,
                (string) $payout->destination->get('recipient_code'),
                $payout->reference,
                'Toneflix earnings withdrawal',
            );
        } catch (ValidationException $e) {
            $result = ['status' => 'failed', 'transfer_code' => null, 'reason' => $e->getMessage(), 'raw' => []];
        }

        return $this->settle($payout, $result);
    }

    /**
     * Record the provider's answer.
     *
     * @param  array{status: string, transfer_code: ?string, reason: ?string, raw: array<string, mixed>}  $result
     */
    protected function settle(Payout $payout, array $result): Payout
    {
        $status = match ($result['status']) {
            'paid' => PayoutStatus::PAID,
            'failed' => PayoutStatus::FAILED,
            default => PayoutStatus::PROCESSING,
        };

        $changed = false;

        $payout = DB::transaction(function () use ($payout, $status, $result, &$changed) {
            /** @var Payout $locked */
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            // Processing can end either way; a paid transfer can still be reversed by the bank.
            $allowed = match ($locked->status) {
                PayoutStatus::PROCESSING => true,
                PayoutStatus::PAID => $status === PayoutStatus::FAILED,
                default => false,
            };

            if (! $allowed) {
                return $locked;
            }

            $changed = $locked->status !== $status;

            $locked->status = $status;
            $locked->transfer_code = $result['transfer_code'] ?? $locked->transfer_code;
            $locked->gateway_response = $result['raw'] ?: $locked->gateway_response;

            if ($status === PayoutStatus::PAID) {
                $locked->paid_at ??= now();
            }

            if ($status === PayoutStatus::FAILED) {
                $locked->failure_reason = $result['reason'] ?: 'The bank did not accept this transfer.';
            }

            $locked->save();

            return $locked;
        });

        if ($changed) {
            $this->announce($payout);
        }

        return $payout;
    }

    /**
     * Tell the creator's open tabs that a payout changed.
     */
    protected function announce(Payout $payout): void
    {
        Realtime::send(Channels::user($payout->user_id), 'payout.updated', [
            'payout' => (new PayoutResource($payout))->resolve(),
        ]);
    }
}
