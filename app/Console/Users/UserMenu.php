<?php

namespace App\Console\Users;

use App\Console\Users\Actions\AdjustCredits;
use App\Console\Users\Actions\AdjustFunds;
use App\Console\Users\Actions\AssignPlan;
use App\Console\Users\Actions\ChangeAccess;
use App\Console\Users\Actions\ConfirmEmail;
use App\Console\Users\Actions\EditProfile;
use App\Console\Users\Actions\EndPlan;
use App\Console\Users\Actions\ResetTwoFactor;
use App\Console\Users\Actions\RestoreAccount;
use App\Console\Users\Actions\SignOut;
use App\Console\Users\Actions\SuspendAccount;
use App\Console\Users\Actions\ToggleContentAgent;
use App\Console\Users\Actions\ToggleVerified;
use App\Models\User;
use App\Services\Ai\Credits;
use App\Services\Auth\TwoFactor;
use App\Services\Payment\Subscriptions;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

/**
 * Shows one account and the actions the console can take on it.
 */
class UserMenu
{
    /** @var list<class-string<UserAction>> */
    public const ACTIONS = [
        EditProfile::class,
        SuspendAccount::class,
        RestoreAccount::class,
        ToggleVerified::class,
        ConfirmEmail::class,
        ChangeAccess::class,
        AssignPlan::class,
        EndPlan::class,
        AdjustCredits::class,
        AdjustFunds::class,
        ToggleContentAgent::class,
        ResetTwoFactor::class,
        SignOut::class,
    ];

    protected const BACK = 'back';

    public function __construct(protected Subscriptions $subscriptions, protected TwoFactor $twoFactor) {}

    /**
     * One line describing an account in a list.
     */
    public static function line(User $user): string
    {
        $roles = $user->roles
            ->pluck('name')
            ->reject(fn($role) => $role === 'user')
            ->implode(', ');

        return collect([
            "#{$user->id}",
            "@{$user->username}",
            $user->fullname,
            "<{$user->email}>",
            $roles ? "[{$roles}]" : null,
            $user->isSuspended() ? ($user->suspended_until ? '(suspended)' : '(banned)') : null,
        ])
            ->filter()
            ->implode('  ');
    }

    public function open(User $user): void
    {
        while (true) {
            $user
                ->refresh()
                ->load('roles');

            $this->show($user);

            $actions = collect(self::ACTIONS)
                ->mapWithKeys(fn($class) => [class_basename($class) => app($class)])
                ->filter(fn(UserAction $action) => $action->available($user));

            $choice = select(
                label: "What do you want to do with @{$user->username}?",
                options: $actions
                    ->map(fn(UserAction $action) => $action->label($user))
                    ->put(self::BACK, '← Back to the list')
                    ->all(),
                scroll: $actions->count() + 1,
            );

            if ($choice === self::BACK) {
                return;
            }

            $this->run($actions->get($choice), $user);
        }
    }

    protected function run(UserAction $action, User $user): void
    {
        try {
            $done = $action->run($user);
        } catch (ValidationException $e) {
            error(collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        if ($done) {
            info($done);
        }
    }

    protected function show(User $user): void
    {
        $plan = $this->subscriptions->current($user)?->planModel;
        $roles = $user
            ->getRoleNames()
            ->implode(', ');
        $permissions = $user
            ->getDirectPermissions()
            ->pluck('name')
            ->implode(', ');
        $credits = Credits::for($user)->total();

        $status = match (true) {
            ! $user->isSuspended() => 'Active',
            $user->suspended_until !== null => "Suspended until {$user->suspended_until->toDayDateTimeString()}: {$user->suspension_reason}",
            default => "Banned: {$user->suspension_reason}",
        };

        table(['Account', "@{$user->username}"], [
            ['ID', $user->id],
            ['Name', $user->fullname ?: '-'],
            ['Email', $user->email . ($user->email_verified_at ? ' (confirmed)' : ' (not confirmed)')],
            ['Status', $status],
            ['Verified badge', $user->verified ? 'Yes' : 'No'],
            ['Roles', $roles ?: '-'],
            ['Direct permissions', $permissions ?: '-'],
            ['Plan', $plan?->name ?? 'Default'],
            ['AI credits', $credits],
            ['Two-factor sign-in', $this->twoFactor->enabled($user) ? 'On' : 'Off'],
            ['Content agent', $user->content_agent ? 'Yes' : 'No'],
            ['Joined', $user->created_at?->toDayDateTimeString() ?? '-'],
        ]);
    }
}
