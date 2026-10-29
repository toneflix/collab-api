<?php

namespace App\Services\Payment;

use App\Broadcasting\Channels;
use App\Enums\PayoutStatus;
use App\Helpers\Providers;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PayoutNotification;
use App\Services\Realtime\Realtime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Valorin\Random\Random;

/**
 * Creators' withdrawable balance, the account they withdraw to, and the life
 * of each withdrawal from request to the money landing in their account.
 */
class Payouts
{
    /** The purpose of an admin's change to a creator's balance. */
    public const ADJUSTMENT = 'adjustment';

    public function __construct(protected PayoutProviders $providers) {}

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
        $cutoff = now()
            ->subDays($this->holdDays());

        $earnings = $user->earnings()
            ->toBase()
            ->selectRaw('coalesce(sum(recipient_share), 0) as earned')
            ->selectRaw('coalesce(sum(case when paid_at <= ? or purpose = ? then recipient_share else 0 end), 0) as cleared', [$cutoff, self::ADJUSTMENT])
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
     * Countries creators can be paid in, with how.
     *
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array
    {
        return $this->providers->countries();
    }

    /**
     * Banks or mobile money networks for a way of getting paid in a country.
     *
     * @return array<int, array{name: string, code: string}>
     */
    public function banks(string $country, string $method): array
    {
        $gateway = $this->providers->forCountry($country);
        $payoutMethod = $this->providers->method($country, $method);

        if (! $gateway instanceof CollectsBankDetails) {
            return [];
        }

        $key = "payouts:banks:{$gateway->key()}:{$country}:{$method}";

        if ($banks = Cache::get($key)) {
            return $banks;
        }

        $banks = $gateway->banks($country, $payoutMethod);

        if ($banks) {
            Cache::put($key, $banks, now()->addDay());
        }

        return $banks;
    }

    /**
     * Check an account so the creator can confirm the name before saving.
     *
     * @param  array<string, string|null>  $details  account_name, and for some countries ID details
     * @return array{account_name: string, account_number: string, bank_code: string, bank_name: string}
     */
    public function resolve(string $country, string $method, string $bankCode, string $accountNumber, array $details = []): array
    {
        $gateway = $this->providers->forCountry($country);
        $payoutMethod = $this->providers->method($country, $method);

        if (! $gateway instanceof CollectsBankDetails) {
            throw ValidationException::withMessages(['method' => 'Set this account up with the provider instead.']);
        }

        if (! $payoutMethod->validNumber($accountNumber)) {
            throw ValidationException::withMessages(['account_number' => "Check the {$payoutMethod->numberLabel}."]);
        }

        if ($payoutMethod->lookup !== PayoutMethod::LOOKUP_RESOLVE && blank($details['account_name'] ?? null)) {
            throw ValidationException::withMessages(['account_name' => 'Enter the name on the account.']);
        }

        $bank = collect($this->banks($country, $method))
            ->firstWhere('code', $bankCode);

        if (! $bank) {
            throw ValidationException::withMessages(['bank_code' => 'Choose from the list.']);
        }

        $name = $gateway->lookup($country, $payoutMethod, $bankCode, $accountNumber, $details);

        return [
            'account_name' => $name ?: (string) ($details['account_name'] ?? ''),
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => $bank['name'],
        ];
    }

    /**
     * Save the bank or mobile money account the creator gets paid into, registered with the provider.
     *
     * @param  array<string, string|null>  $details
     */
    public function saveAccount(User $user, string $country, string $method, string $bankCode, string $accountNumber, array $details = []): PayoutAccount
    {
        $gateway = $this->providers->forCountry($country);
        $resolved = $this->resolve($country, $method, $bankCode, $accountNumber, $details);
        $current = $user
            ->payoutAccount()
            ->first();

        $unchanged = $current
            && $current->recipient_code
            && $current->provider === $gateway->key()
            && $current->method === $method
            && $current->bank_code === $bankCode
            && $current->account_number === $accountNumber
            && $current->account_name === $resolved['account_name'];

        /** @var CollectsBankDetails $gateway */
        $recipient = $unchanged
            ? $current->recipient_code
            : $gateway->createRecipient($country, $this->providers->method($country, $method), $resolved['account_name'], $bankCode, $accountNumber);

        return $user
            ->payoutAccount()
            ->updateOrCreate([], [
                ...$resolved,
                'provider' => $gateway->key(),
                'country' => $country,
                'method' => $method,
                'currency' => $gateway->currencyFor($country),
                'recipient_code' => $recipient,
                'status' => 'active',
            ]);
    }

    /**
     * Start or continue the provider's own onboarding for the creator, and return its link.
     */
    public function connect(User $user, string $country, string $returnUrl): string
    {
        $gateway = $this->providers->forCountry($country);

        if (! $gateway instanceof HostsPayoutAccounts) {
            throw ValidationException::withMessages(['country' => 'Add your account details here instead.']);
        }

        $current = $user
            ->payoutAccount()
            ->first();
        $existing = $current?->provider === $gateway->key() && $current->country === $country ? $current->recipient_code : null;

        $link = $gateway->onboard($user, $country, $existing, $returnUrl);

        $user
            ->payoutAccount()
            ->updateOrCreate([], [
                'provider' => $gateway->key(),
                'country' => $country,
                'method' => 'hosted',
                'currency' => $gateway->currencyFor($country),
                'recipient_code' => $link['account_id'],
                'status' => $existing && $current->status === 'active' ? 'active' : 'pending',
                'bank_code' => null,
                'bank_name' => null,
                'account_number' => null,
                'account_name' => $user->fullname ?: $user->username,
            ]);

        return $link['url'];
    }

    /**
     * The creator's account, with a hosted account's onboarding checked again while it is pending.
     */
    public function account(User $user): ?PayoutAccount
    {
        $account = $user
            ->payoutAccount()
            ->first();

        if ($account?->status !== 'pending' || ! $account->recipient_code) {
            return $account;
        }

        $gateway = $this->providers->get($account->provider);

        try {
            if ($gateway instanceof HostsPayoutAccounts && $gateway->ready($account->recipient_code)) {
                $account->update(['status' => 'active']);
            }
        } catch (ValidationException) {
            // The provider can't be reached; it stays pending until the next look.
        }

        return $account;
    }

    /**
     * Ask to withdraw part of the available balance.
     */
    public function request(User $user, float $amount): Payout
    {
        $amount = round($amount, 2);

        $payout = DB::transaction(function () use ($user, $amount) {
            // One request at a time per creator, so two at once can't both spend the same balance
            User::whereKey($user->id)
                ->lockForUpdate()
                ->first();

            $account = $user
                ->payoutAccount()
                ->first();

            if (! $account?->recipient_code) {
                throw ValidationException::withMessages(['account' => 'Add the account to pay into first.']);
            }

            if ($account->status !== 'active') {
                throw ValidationException::withMessages(['account' => 'Finish setting up your payout account first.']);
            }

            if ($amount < $this->minimum()) {
                throw ValidationException::withMessages(['amount' => 'The smallest withdrawal is ' . $this->minimum() . '.']);
            }

            if ($amount > $this->summary($user)['available']) {
                throw ValidationException::withMessages(['amount' => 'That is more than your available balance.']);
            }

            return $user
                ->payouts()
                ->create([
                    'reference' => 'po_' . Random::string(24, true, false, true, false),
                    'amount' => $amount,
                    'currency' => $this->currency(),
                    'status' => PayoutStatus::PENDING,
                    'provider' => $account->provider,
                    'destination' => $account->snapshot(),
                ]);
        });

        if (Providers::config('payout_auto_approve', false)) {
            return $this->send($payout);
        }

        return $payout;
    }

    /**
     * An admin, or the console when null, adds to or takes from the creator's
     * balance. It is available at once, and taking more than is available is refused.
     */
    public function adjust(User $user, float $amount, string $note, ?User $admin): Transaction
    {
        $amount = round($amount, 2);

        if ($amount == 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount other than zero.']);
        }

        return DB::transaction(function () use ($user, $amount, $note, $admin) {
            User::whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if ($amount < 0 && -$amount > $this->summary($user)['available']) {
                throw ValidationException::withMessages(['amount' => 'That is more than the available balance.']);
            }

            $transaction = new Transaction([
                'reference' => 'adj_' . Random::string(24, true, false, true, false),
                'purpose' => self::ADJUSTMENT,
                'method' => 'admin',
                'status' => 'complete',
                'amount' => 0,
                'due' => 0,
                'recipient_id' => $user->id,
                'recipient_share' => $amount,
                'meta' => ['message' => $note, 'admin_id' => $admin?->id],
                'paid_at' => now(),
            ]);
            $transaction
                ->transactable()
                ->associate($user);
            $transaction->save();

            return $transaction;
        });
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

        $result = $this->providers
            ->get($payout->provider)
            ->verifyTransfer($payout->reference, $payout->transfer_code);

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
            $locked = Payout::whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

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
            $gateway = $this->providers->get($payout->provider);
            $payout = $this->convert($payout, $gateway);

            $result = $gateway->transfer(new PayoutTransfer(
                amount: (float) $payout->payout_amount,
                currency: (string) $payout->payout_currency,
                sourceCurrency: $payout->currency,
                recipient: (string) $payout->destination->get('recipient_code'),
                reference: $payout->reference,
                reason: config('app.name') . ' earnings withdrawal',
                country: (string) $payout->destination->get('country'),
                method: (string) $payout->destination->get('method', 'bank'),
                destination: $payout->destination->all(),
            ));
        } catch (ValidationException $e) {
            $result = ['status' => 'failed', 'transfer_code' => null, 'reason' => $e->getMessage(), 'raw' => []];
        }

        return $this->settle($payout, $result);
    }

    /**
     * Work out what the creator receives in the currency they are paid in. The rate
     * is fixed the first time, so a retry sends the same amount.
     */
    protected function convert(Payout $payout, PayoutGateway $gateway): Payout
    {
        if ($payout->payout_amount !== null) {
            return $payout;
        }

        $to = $gateway->currencyFor((string) $payout->destination->get('country')) ?: $payout->currency;
        $rate = $gateway->rate($payout->currency, $to);

        $payout->forceFill([
            'payout_currency' => $to,
            'exchange_rate' => $rate,
            'payout_amount' => round($payout->amount * $rate, 2),
        ])->save();

        return $payout;
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
            $locked = Payout::whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

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
     * Tell the creator that a payout changed: their open tabs always, and by
     * notification once it is paid, failed or rejected.
     */
    protected function announce(Payout $payout): void
    {
        Realtime::send(Channels::user($payout->user_id), 'payout.updated', [
            'payout' => (new PayoutResource($payout))->resolve(),
        ]);

        if (PayoutNotification::covers($payout->status)) {
            $payout->user
                ?->notify(new PayoutNotification($payout));
        }
    }
}
