<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use App\Services\Admin\UserDirectory;
use App\Services\Admin\UserManager;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Payment\Subscriptions;
use Illuminate\Validation\ValidationException;

/**
 * A tool that looks at or changes user accounts through UserManager.
 */
abstract class UserTool extends AssistantTool
{
    public function permissions(): array
    {
        return ['manage-users'];
    }

    /**
     * UserManager, with its audit entries marked as made through the assistant.
     */
    protected function manager(): UserManager
    {
        return app(UserManager::class)->via('assistant');
    }

    /**
     * Find the account the admin means by id, username or email.
     */
    protected function user(string $key): User
    {
        $user = app(UserDirectory::class)->find(ltrim(trim($key), '@'));

        if (! $user) {
            throw ValidationException::withMessages(['user' => "No account matches \"{$key}\"."]);
        }

        return $user;
    }

    /**
     * The account in the input, once UserManager says the admin may do this to it.
     *
     * @param  array<string, mixed>  $input
     */
    protected function authorized(User $admin, array $input, string $ability): User
    {
        $user = $this->user($input['user']);

        $this->manager()->authorize($admin, $ability, $user);

        return $user;
    }

    /**
     * A short description of an account.
     *
     * @return array<string, mixed>
     */
    protected function brief(User $user): array
    {
        $plan = app(Subscriptions::class)->current($user)?->planModel;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->fullname,
            'email' => $user->email,
            'status' => $user->isSuspended() ? ($user->suspended_until ? 'suspended' : 'banned') : 'active',
            'suspendedUntil' => $user->suspended_until?->toIso8601String(),
            'suspensionReason' => $user->suspension_reason,
            'verified' => (bool) $user->verified,
            'roles' => $user
                ->getRoleNames()
                ->all(),
            'plan' => $plan?->name,
            'joined' => $user->created_at?->toDateString(),
        ];
    }

    /**
     * The handle used in summaries, like "@jane (#12)".
     */
    protected function handle(User $user): string
    {
        return "@{$user->username} (#{$user->id})";
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function userField(): array
    {
        return ['required|string|max:255', 'The account\'s id, username or email'];
    }
}
