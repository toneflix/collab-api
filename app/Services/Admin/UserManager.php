<?php

namespace App\Services\Admin;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Models\AdminAction;
use App\Models\AiCreditEntry;
use App\Models\FeatureGrant;
use App\Models\Plan;
use App\Models\User;
use App\Services\Ai\Credits;
use App\Services\Auth\TwoFactor;
use App\Services\Payment\Payouts;
use App\Services\Payment\Subscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * What admins can do to user accounts, and the audit trail of each change.
 * It is also the one place that decides which admin may do what to whom.
 * A null admin means the change came from the console.
 */
class UserManager
{
    /** Permissions each action needs, on top of manage-users. */
    public const ABILITIES = [
        'view' => [],
        'update' => [],
        'suspend' => [],
        'verify' => [],
        'credits' => [],
        'plan' => ['manage-plans'],
        'features' => ['manage-plans'],
        'funds' => ['manage-payouts'],
        'roles' => ['manage-admins'],
    ];

    /** Where changes come from when not the admin pages, like "assistant" */
    protected ?string $via = null;

    public function __construct(
        protected Subscriptions $subscriptions,
        protected Payouts $payouts,
        protected TwoFactor $twoFactor,
    ) {}

    /**
     * A manager whose audit entries say the change came through another
     * channel, such as the admin assistant.
     */
    public function via(string $via): static
    {
        $manager = clone $this;
        $manager->via = $via;

        return $manager;
    }

    public static function allows(?User $admin): bool
    {
        return (bool) $admin?->checkPermissionTo('manage-users');
    }

    public static function superAdminRole(): string
    {
        return (string) config('permission-defs.super-admin-role', 'super-admin');
    }

    public function can(User $admin, string $ability, User $user): bool
    {
        $permissions = ['manage-users', ...(self::ABILITIES[$ability] ?? ['-'])];

        if (! collect($permissions)->every(fn($p) => $admin->checkPermissionTo($p))) {
            return false;
        }

        if ($ability === 'view') {
            return true;
        }

        // Only super admins change super admins, and only admin managers act against other staff.
        if ($user->hasRole(self::superAdminRole()) && ! $admin->hasRole(self::superAdminRole())) {
            return false;
        }

        if (in_array($ability, ['suspend', 'roles']) && $admin->is($user)) {
            return false;
        }

        return ! ($ability === 'suspend' && $this->isStaff($user) && ! $admin->checkPermissionTo('manage-admins'));
    }

    /**
     * @return array<string, bool>
     */
    public function abilities(User $admin, User $user): array
    {
        return collect(array_keys(self::ABILITIES))
            ->mapWithKeys(fn($ability) => [$ability => $this->can($admin, $ability, $user)])
            ->all();
    }

    public function authorize(User $admin, string $ability, User $user): void
    {
        abort_unless($this->can($admin, $ability, $user), HttpStatus::FORBIDDEN->value, 'You can\'t do that to this account.');
    }

    public function isStaff(User $user): bool
    {
        return $user->getAllPermissions()->pluck('name')
            ->intersect([...AdminStats::PERMISSIONS, 'manage-admins'])
            ->isNotEmpty();
    }

    /**
     * Rules for the profile fields admins can change, each optional.
     *
     * @return array<string, list<mixed>>
     */
    public function profileRules(User $user): array
    {
        return [
            'firstname' => ['sometimes', 'required', 'string', 'max:100'],
            'lastname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'username' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'about' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Change profile details. Only fields that changed are recorded.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(?User $admin, User $user, array $data): User
    {
        return DB::transaction(function () use ($admin, $user, $data) {
            $user->fill($data);
            $changes = collect($user->getDirty())
                ->map(fn($value, $field) => ['from' => $user->getOriginal($field), 'to' => $value])
                ->all();

            if (! $changes) {
                return $user;
            }

            $user->save();
            $this->record($admin, $user, 'profile.updated', null, ['changes' => $changes]);

            return $user;
        });
    }

    /**
     * Suspend until a date, or ban when no date is given. The user is signed out everywhere.
     */
    public function suspend(?User $admin, User $user, ?Carbon $until, string $reason): User
    {
        return DB::transaction(function () use ($admin, $user, $until, $reason) {
            $user->forceFill([
                'suspended_at' => now(),
                'suspended_until' => $until,
                'suspension_reason' => $reason,
            ])->save();

            $user
                ->tokens()
                ->delete();

            $this->record($admin, $user, $until ? 'account.suspended' : 'account.banned', $reason, [
                'until' => $until?->toIso8601String(),
            ]);

            return $user;
        });
    }

    public function unsuspend(?User $admin, User $user, ?string $reason = null): User
    {
        if (! $user->isSuspended()) {
            throw ValidationException::withMessages(['user' => 'This account isn\'t suspended.']);
        }

        return DB::transaction(function () use ($admin, $user, $reason) {
            $user
                ->forceFill(['suspended_at' => null, 'suspended_until' => null, 'suspension_reason' => null])
                ->save();
            $this->record($admin, $user, 'account.restored', $reason);

            return $user;
        });
    }

    /**
     * Give or take away the verified badge, and confirm the email address when giving it.
     */
    public function verify(?User $admin, User $user, bool $verified, bool $email = false): User
    {
        return DB::transaction(function () use ($admin, $user, $verified, $email) {
            $user->verified = $verified;

            if ($email && ! $user->email_verified_at) {
                $user->email_verified_at = now();
            }

            $changed = $user->getDirty();

            if (! $changed) {
                return $user;
            }

            $user->save();

            if (array_key_exists('verified', $changed)) {
                $this->record($admin, $user, $verified ? 'account.verified' : 'account.unverified');
            }

            if (array_key_exists('email_verified_at', $changed)) {
                $this->record($admin, $user, 'email.verified');
            }

            return $user;
        });
    }

    /**
     * Replace the user's roles.
     *
     * @param  array<int, string>  $roles
     */
    public function syncRoles(?User $admin, User $user, array $roles): User
    {
        $super = self::superAdminRole();
        $before = $user
            ->getRoleNames()
            ->sort()
            ->values()
            ->all();
        $after = collect($roles)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($admin && in_array($super, array_merge(array_diff($after, $before), array_diff($before, $after))) && ! $admin->hasRole($super)) {
            throw ValidationException::withMessages(['roles' => 'Only super admins can give or take the super admin role.']);
        }

        if ($before === $after) {
            return $user;
        }

        return DB::transaction(function () use ($admin, $user, $before, $after) {
            $user->syncRoles($after);
            $this->record($admin, $user, 'roles.changed', null, ['from' => $before, 'to' => $after]);

            return $user;
        });
    }

    /**
     * Replace the permissions given to the user directly, apart from their roles.
     *
     * @param  array<int, string>  $permissions
     */
    public function syncPermissions(?User $admin, User $user, array $permissions): User
    {
        $before = $user
            ->getDirectPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $after = collect($permissions)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($before === $after) {
            return $user;
        }

        return DB::transaction(function () use ($admin, $user, $before, $after) {
            $user->syncPermissions($after);
            $this->record($admin, $user, 'permissions.changed', null, ['from' => $before, 'to' => $after]);

            return $user;
        });
    }

    public function assignPlan(?User $admin, User $user, Plan $plan, int $cycles, ?string $reason = null): User
    {
        if ($plan->is_default) {
            throw ValidationException::withMessages(['plan_id' => 'Everyone is on the default plan already. Remove their plan instead.']);
        }

        return DB::transaction(function () use ($admin, $user, $plan, $cycles, $reason) {
            $from = $this->subscriptions->current($user)?->planModel?->name;
            $subscription = $this->subscriptions->assign($user, $plan, $cycles);

            $this->record($admin, $user, 'plan.assigned', $reason, [
                'from' => $from,
                'plan' => $plan->name,
                'period' => $plan->period($cycles),
                'until' => $subscription->ends_at->toIso8601String(),
            ]);

            return $user;
        });
    }

    public function endPlan(?User $admin, User $user, ?string $reason = null): User
    {
        return DB::transaction(function () use ($admin, $user, $reason) {
            $plan = $this->subscriptions->current($user)?->planModel?->name;

            if (! $this->subscriptions->end($user)) {
                throw ValidationException::withMessages(['plan' => 'This account is on the default plan already.']);
            }

            $this->record($admin, $user, 'plan.removed', $reason, ['plan' => $plan]);

            return $user;
        });
    }

    /**
     * Give the account a plan feature on top of its plan, until a date or
     * until revoked. Granting it again replaces the earlier grant.
     */
    public function grantFeature(?User $admin, User $user, Feature $feature, ?Carbon $until = null, ?string $reason = null): FeatureGrant
    {
        if (! in_array($feature, Feature::grantable(), true)) {
            throw ValidationException::withMessages(['feature' => 'Only on or off features can be granted.']);
        }

        return DB::transaction(function () use ($admin, $user, $feature, $until, $reason) {
            $grant = $user
                ->featureGrants()
                ->updateOrCreate(['feature' => $feature->value], [
                    'granted_by_id' => $admin?->id,
                    'reason' => $reason,
                    'expires_at' => $until,
                ]);

            $this->record($admin, $user, 'feature.granted', $reason, [
                'feature' => $feature->title(),
                'until' => $until?->toIso8601String(),
            ]);

            return $grant;
        });
    }

    /**
     * Take back a feature an admin granted. What the plan gives stays.
     */
    public function revokeFeature(?User $admin, User $user, Feature $feature, ?string $reason = null): User
    {
        return DB::transaction(function () use ($admin, $user, $feature, $reason) {
            $deleted = $user
                ->featureGrants()
                ->where('feature', $feature->value)
                ->delete();

            if (! $deleted) {
                throw ValidationException::withMessages(['feature' => 'This account wasn\'t granted that feature.']);
            }

            $this->record($admin, $user, 'feature.revoked', $reason, ['feature' => $feature->title()]);

            return $user;
        });
    }

    /**
     * Add to or take from the creator's withdrawable balance.
     */
    public function adjustFunds(?User $admin, User $user, float $amount, string $reason): User
    {
        return DB::transaction(function () use ($admin, $user, $amount, $reason) {
            $transaction = $this->payouts->adjust($user, $amount, $reason, $admin);

            $this->record($admin, $user, $amount > 0 ? 'funds.added' : 'funds.removed', $reason, [
                'amount' => $transaction->recipient_share,
                'currency' => $this->payouts->currency(),
                'reference' => $transaction->reference,
            ]);

            return $user;
        });
    }

    /**
     * Make the account one the content agent builds for, or stop it being one.
     */
    public function setContentAgent(?User $admin, User $user, bool $enabled): User
    {
        if ($user->content_agent === $enabled) {
            return $user;
        }

        return DB::transaction(function () use ($admin, $user, $enabled) {
            $user
                ->forceFill(['content_agent' => $enabled])
                ->save();

            $this->record($admin, $user, $enabled ? 'agent.assigned' : 'agent.removed');

            return $user;
        });
    }

    /**
     * Grant AI credits, or take back bought and granted ones.
     */
    public function adjustCredits(?User $admin, User $user, int $credits, string $reason): User
    {
        if ($credits === 0) {
            throw ValidationException::withMessages(['credits' => 'Enter a number of credits other than zero.']);
        }

        return DB::transaction(function () use ($admin, $user, $credits, $reason) {
            $wallet = Credits::for($user);

            $credits > 0
                ? $wallet->add($credits, $reason, null, AiCreditEntry::GRANT)
                : $wallet->deduct(-$credits, $reason);

            $this->record($admin, $user, $credits > 0 ? 'credits.added' : 'credits.removed', $reason, ['credits' => abs($credits)]);

            return $user;
        });
    }

    /**
     * Turn two-factor sign-in off for an owner who lost their authenticator
     * app and recovery codes. They sign in with their password and set it up again.
     */
    public function resetTwoFactor(?User $admin, User $user, ?string $reason = null): User
    {
        if (! $this->twoFactor->enabled($user)) {
            throw ValidationException::withMessages(['user' => 'Two-factor sign-in is off for this account.']);
        }

        return DB::transaction(function () use ($admin, $user, $reason) {
            $this->twoFactor->reset($user);
            $this->record($admin, $user, 'two_factor.reset', $reason);

            return $user;
        });
    }

    /**
     * The rules a new password must meet, the same as at sign-up.
     *
     * @return list<mixed>
     */
    public function passwordRules(): array
    {
        return ['required', 'string', Password::defaults()];
    }

    /**
     * Set a new password, checked against the sign-up password rules,
     * and optionally sign the user out on every device.
     *
     * @throws ValidationException
     */
    public function changePassword(?User $admin, User $user, string $password, bool $signOut = true, ?string $reason = null): User
    {
        Validator::make(['password' => $password], ['password' => $this->passwordRules()])
            ->validate();

        return DB::transaction(function () use ($admin, $user, $password, $signOut, $reason) {
            $user->password = $password;
            $user->save();

            $sessions = $signOut
                ? $user
                    ->tokens()
                    ->delete()
                : null;

            $this->record($admin, $user, 'password.changed', $reason, ['sessions' => $sessions]);

            return $user;
        });
    }

    /**
     * Sign the user out on every device.
     */
    public function signOut(?User $admin, User $user, ?string $reason = null): User
    {
        return DB::transaction(function () use ($admin, $user, $reason) {
            $sessions = $user
                ->tokens()
                ->delete();

            $this->record($admin, $user, 'sessions.ended', $reason, ['sessions' => $sessions]);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function record(?User $admin, User $user, string $action, ?string $reason = null, array $details = []): AdminAction
    {
        if (! $admin) {
            $details['via'] = 'console';
        } elseif ($this->via) {
            $details['via'] = $this->via;
        }

        return AdminAction::query()
            ->create([
                'admin_id' => $admin?->id,
                'user_id' => $user->id,
                'action' => $action,
                'reason' => $reason,
                'details' => array_filter($details, fn($value) => $value !== null) ?: null,
            ]);
    }
}
