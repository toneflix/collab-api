<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use Illuminate\Validation\Rule;

class SetRoles extends UserTool
{
    public function name(): string
    {
        return 'set_roles';
    }

    public function description(): string
    {
        return 'Replace an account\'s roles with the given list. Use show_user first to see the current ones.';
    }

    public function input(): array
    {
        $roles = config('permission-defs.roles', []);

        return [
            'user' => $this->userField(),
            'roles' => ['present|array', 'Every role the account should have, from: ' . implode(', ', $roles)],
            'roles.*' => [['string', Rule::in($roles)], 'A role name'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'roles');
        $roles = implode(', ', $input['roles']) ?: 'no roles';

        return "Change the roles of {$this->handle($user)} from " . ($user->getRoleNames()->join(', ') ?: 'none') . " to {$roles}";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'roles');

        $this->manager()->syncRoles($admin, $user, $input['roles']);

        return $this->brief($user);
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
