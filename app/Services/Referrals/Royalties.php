<?php

namespace App\Services\Referrals;

use App\Models\AdminAction;
use App\Models\RoyaltyContract;
use App\Models\RoyaltyInvite;
use App\Models\User;
use App\Notifications\RoyaltyInviteNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The royalty program: admins invite partners on their own terms, and each
 * accepted invite becomes a contract that sets what the royal earns from the
 * people they invite. Every change is written to the admin audit trail.
 */
class Royalties
{
    public const INVITE_DAYS = 14;

    public function __construct(protected Referrals $referrals = new Referrals) {}

    /**
     * Invite someone, who may or may not have an account yet, and email them the link.
     *
     * @param  array{email: string, name: string, rate: float|int|string, months?: int|string|null, code?: ?string, note?: ?string}  $data
     */
    public function invite(User $admin, array $data): RoyaltyInvite
    {
        $email = Str::lower(trim($data['email']));
        $code = isset($data['code']) && trim((string) $data['code']) !== '' ? Str::lower(trim((string) $data['code'])) : null;
        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if ($user && $user->currentRoyaltyContract()) {
            throw ValidationException::withMessages(['email' => 'This person already has a royalty contract.']);
        }

        $openInvite = RoyaltyInvite::query()
            ->open()
            ->where('email', $email)
            ->exists();

        if ($openInvite) {
            throw ValidationException::withMessages(['email' => 'This person already has an open invite.']);
        }

        $this->ensureCodeIsFree($code, $user);

        $invite = RoyaltyInvite::query()
            ->create([
                'email' => $email,
                'name' => trim($data['name']),
                'rate' => (float) $data['rate'],
                'months' => (int) ($data['months'] ?? 0),
                'code' => $code,
                'note' => $data['note'] ?? null,
                'token' => Str::random(48),
                'user_id' => $user?->id,
                'invited_by_id' => $admin->id,
                'expires_at' => now()->addDays(self::INVITE_DAYS),
            ]);

        $this->send($invite);
        $this->record($admin, $user, 'royalty.invited', ['invite_id' => $invite->id, 'email' => $email, 'rate' => $invite->rate]);

        return $invite;
    }

    /**
     * Send the invite again with a fresh expiry.
     */
    public function resend(User $admin, RoyaltyInvite $invite): RoyaltyInvite
    {
        if ($invite->accepted_at || $invite->cancelled_at) {
            throw ValidationException::withMessages(['invite' => 'This invite is closed.']);
        }

        $invite->expires_at = now()->addDays(self::INVITE_DAYS);
        $invite->save();

        $this->send($invite);
        $this->record($admin, $invite->user, 'royalty.resent', ['invite_id' => $invite->id]);

        return $invite;
    }

    public function cancel(User $admin, RoyaltyInvite $invite): RoyaltyInvite
    {
        if ($invite->accepted_at) {
            throw ValidationException::withMessages(['invite' => 'This invite was already accepted.']);
        }

        $invite->cancelled_at ??= now();
        $invite->save();

        $this->record($admin, $invite->user, 'royalty.cancelled', ['invite_id' => $invite->id]);

        return $invite;
    }

    /**
     * An open invite by its emailed token.
     */
    public function findOpenInvite(?string $token): ?RoyaltyInvite
    {
        if (! $token) {
            return null;
        }

        return RoyaltyInvite::query()
            ->open()
            ->where('token', $token)
            ->first();
    }

    /**
     * Turn an invite into the user's contract and give them the invite's code.
     */
    public function accept(RoyaltyInvite $invite, User $user): RoyaltyContract
    {
        if ($invite->status() !== RoyaltyInvite::PENDING) {
            throw ValidationException::withMessages(['token' => 'This invite is no longer open.']);
        }

        if (Str::lower($user->email ?? '') !== $invite->email) {
            throw ValidationException::withMessages(['token' => 'This invite was sent to a different email address.']);
        }

        if ($user->currentRoyaltyContract()) {
            throw ValidationException::withMessages(['token' => 'You already have a royalty contract.']);
        }

        return DB::transaction(function () use ($invite, $user) {
            $this->ensureCodeIsFree($invite->code, $user, $invite);

            $contract = $user
                ->royaltyContracts()
                ->create([
                    'royalty_invite_id' => $invite->id,
                    'rate' => $invite->rate,
                    'months' => $invite->months,
                    'note' => $invite->note,
                    'created_by_id' => $invite->invited_by_id,
                    'starts_at' => now(),
                ]);

            $invite->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

            if ($invite->code) {
                $this->referrals->setCode($user, $invite->code);
            } else {
                $this->referrals->codeFor($user);
            }

            $this->record($invite->invitedBy, $user, 'royalty.accepted', ['invite_id' => $invite->id, 'contract_id' => $contract->id]);

            return $contract;
        });
    }

    /**
     * Change a royal's terms: the current contract ends and a new one starts, so
     * earnings always show which terms paid them. A new code replaces the old one.
     *
     * @param  array{rate?: float|int|string, months?: int|string|null, note?: ?string, code?: ?string}  $data
     */
    public function change(User $admin, RoyaltyContract $contract, array $data): RoyaltyContract
    {
        if (! $contract->isActive()) {
            throw ValidationException::withMessages(['contract' => 'This contract has ended.']);
        }

        $user = $contract->user;
        $code = isset($data['code']) && trim((string) $data['code']) !== '' ? Str::lower(trim((string) $data['code'])) : null;

        return DB::transaction(function () use ($admin, $contract, $data, $user, $code) {
            if ($code && $code !== $user->referral_code) {
                $this->ensureCodeIsFree($code, $user);
                $this->referrals->setCode($user, $code);
            }

            $rate = (float) ($data['rate'] ?? $contract->rate);
            $months = (int) ($data['months'] ?? $contract->months);

            if ($rate === $contract->rate && $months === $contract->months) {
                $contract->note = array_key_exists('note', $data) ? $data['note'] : $contract->note;
                $contract->save();

                return $contract;
            }

            $contract->forceFill(['ends_at' => now(), 'ended_by_id' => $admin->id])->save();

            $next = $user
                ->royaltyContracts()
                ->create([
                    'royalty_invite_id' => $contract->royalty_invite_id,
                    'rate' => $rate,
                    'months' => $months,
                    'note' => array_key_exists('note', $data) ? $data['note'] : $contract->note,
                    'created_by_id' => $admin->id,
                    'starts_at' => now(),
                ]);

            $this->record($admin, $user, 'royalty.changed', [
                'contract_id' => $next->id,
                'from' => ['rate' => $contract->rate, 'months' => $contract->months],
                'to' => ['rate' => $rate, 'months' => $months],
            ]);

            return $next;
        });
    }

    /**
     * End a royal's contract. People they invited fall back to the loyalty terms.
     */
    public function end(User $admin, RoyaltyContract $contract, ?string $reason = null): RoyaltyContract
    {
        if (! $contract->isActive()) {
            return $contract;
        }

        $contract->forceFill(['ends_at' => now(), 'ended_by_id' => $admin->id])->save();

        $this->record($admin, $contract->user, 'royalty.ended', ['contract_id' => $contract->id], $reason);

        return $contract;
    }

    protected function send(RoyaltyInvite $invite): void
    {
        if ($invite->user) {
            $invite->user->notify(new RoyaltyInviteNotification($invite));

            return;
        }

        Notification::route('mail', $invite->email)
            ->notify(new RoyaltyInviteNotification($invite));
    }

    /**
     * Codes are shared by users and open invites, so one can't take another's.
     */
    protected function ensureCodeIsFree(?string $code, ?User $user, ?RoyaltyInvite $except = null): void
    {
        if (! $code) {
            return;
        }

        $takenByUser = User::query()
            ->where('referral_code', $code)
            ->when($user, fn($q) => $q->whereKeyNot($user->id))
            ->exists();
        $takenByInvite = RoyaltyInvite::query()
            ->open()
            ->where('code', $code)
            ->when($except, fn($q) => $q->whereKeyNot($except->id))
            ->exists();

        if ($takenByUser || $takenByInvite) {
            throw ValidationException::withMessages(['code' => 'That code is taken.']);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function record(?User $admin, ?User $user, string $action, array $details = [], ?string $reason = null): void
    {
        if (! $user) {
            return;
        }

        AdminAction::query()
            ->create([
                'admin_id' => $admin?->id,
                'user_id' => $user->id,
                'action' => $action,
                'reason' => $reason,
                'details' => $details,
            ]);
    }
}
