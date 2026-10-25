<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\UserDirectory;
use App\Services\Admin\UserManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserAccess extends Command
{
    protected $signature = '
        app:user-access
            {user : The id, username or email of the user}
            {--grant-role=* : Roles to give, by name or id}
            {--revoke-role=* : Roles to take away, by name or id}
            {--grant-permission=* : Permissions to give directly, by name or id}
            {--revoke-permission=* : Permissions to take away, by name or id}
    ';

    protected $description = 'Show, grant or revoke a user\'s roles and permissions';

    public function handle(UserDirectory $directory, UserManager $manager): int
    {
        $user = $directory->find((string) $this->argument('user'));

        if (! $user) {
            $this->error('No user matches "' . $this->argument('user') . '".');

            return self::FAILURE;
        }

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $roles = $this->resolve(Role::class, 'role');
        $permissions = $this->resolve(Permission::class, 'permission');

        if ($roles === null || $permissions === null) {
            return self::FAILURE;
        }

        if ($roles['grant']->isNotEmpty() || $roles['revoke']->isNotEmpty()) {
            $manager->syncRoles(null, $user, $user->getRoleNames()->merge($roles['grant'])->diff($roles['revoke'])->values()->all());
        }

        if ($permissions['grant']->isNotEmpty() || $permissions['revoke']->isNotEmpty()) {
            $current = $user
                ->getDirectPermissions()
                ->pluck('name');
            $manager->syncPermissions(null, $user, $current->merge($permissions['grant'])->diff($permissions['revoke'])->values()->all());
        }

        $this->show($user->fresh());

        return self::SUCCESS;
    }

    /**
     * Names to grant and revoke for one kind of access, or null when some don't exist.
     *
     * @param  class-string<Role|Permission>  $model
     * @return array{grant: Collection<int, string>, revoke: Collection<int, string>}|null
     */
    protected function resolve(string $model, string $kind): ?array
    {
        $resolved = [];

        foreach (['grant', 'revoke'] as $action) {
            $values = collect((array) $this->option("{$action}-{$kind}"))
                ->filter()
                ->unique()
                ->values();

            [$ids, $names] = $values->partition(fn($value) => ctype_digit((string) $value));
            $found = $model::query()
                ->where(fn($q) => $q->whereIn('name', $names->all())->orWhereIn('id', $ids->all()))
                ->get(['id', 'name']);

            $missing = $values->reject(fn($value) => $found->contains(fn($m) => $m->name === $value || (string) $m->id === (string) $value));

            if ($missing->isNotEmpty()) {
                $this->error("Unknown {$kind}: " . $missing->implode(', ') . '. Run app:sync-roles to create the defaults.');

                return null;
            }

            $resolved[$action] = $found
                ->pluck('name')
                ->values();
        }

        return $resolved;
    }

    protected function show(User $user): void
    {
        $this->info("@{$user->username} ({$user->email})");
        $this->table(['Access', 'Names'], [
            ['Roles', $user->getRoleNames()->implode(', ') ?: '-'],
            ['Direct permissions', $user->getDirectPermissions()->pluck('name')->implode(', ') ?: '-'],
            ['All permissions', $user->getAllPermissions()->pluck('name')->sort()->implode(', ') ?: '-'],
        ]);
    }
}
