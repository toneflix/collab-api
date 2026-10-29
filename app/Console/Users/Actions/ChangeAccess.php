<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Laravel\Prompts\multiselect;

class ChangeAccess extends UserAction
{
    public function label(User $user): string
    {
        return 'Change roles and permissions';
    }

    public function run(User $user): ?string
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $roleNames = Role::query()
            ->orderBy('name')
            ->pluck('name', 'name');
        $permissionNames = Permission::query()
            ->orderBy('name')
            ->pluck('name', 'name');

        $roles = multiselect(
            label: 'Roles',
            options: $roleNames->all(),
            default: $user
                ->getRoleNames()
                ->all(),
            scroll: 10,
            hint: 'Space picks a role, Enter saves.',
        );
        $permissions = multiselect(
            label: 'Permissions given directly, apart from the roles',
            options: $permissionNames->all(),
            default: $user
                ->getDirectPermissions()
                ->pluck('name')
                ->all(),
            scroll: 10,
            hint: 'Space picks a permission, Enter saves.',
        );

        $this->manager->syncRoles(null, $user, $roles);
        $this->manager->syncPermissions(null, $user, $permissions);

        return 'Roles and permissions saved.';
    }
}
