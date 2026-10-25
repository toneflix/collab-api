<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SyncRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = '
        app:sync-roles
            {users?* : The ID(s) of the user(s) to assign the roles and permissions to}
            {--x|remove : Remove the roles and permissions from the user(s)}
            {--r|roles=* : The roles to assign to the user(s)}
            {--p|permissions=* : The permissions to assign to the user(s)}
            {--f|force : Force the operation to run when in production (Not Implemented)}
            {--s|supes : Force grant the super admin role role}
            {--t|reset : Resets the permissions and roles, when makin (Use with caution)}
            {--m|make : Create the roles and permissions (Runs by default if user(s) are not specified)}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('remove')) {
            $this->remove();
        } elseif ($this->option('make') || empty($this->argument('users'))) {
            if ($this->option('reset')) {
                $this->truncate();
            }
            $this->make();
        } else {
            $this->sync();
        }
    }

    /**
     * Remove the roles and permissions from the specified user(s).
     */
    public function remove()
    {
        $users = $this->argument('users');
        $roles = $this->option('roles');
        $supes = $this->option('supes');
        $permissions = $this->option('permissions');

        /** @var Collection<TKey,User> */
        $users = app(config('permission-defs.user-model', []))->findMany($users);

        $conf = str_ireplace(
            ["\n", "\t", '  '],
            ["\n ", '', ''],
            'You have not specified any roles or permissions.
            Do you want to remove all roles from the user(s) ' .
                (! $supes
                    ? '(This excludes the "' . config('permission-defs.super-admin-role', 'super-admin') . ' role)?'
                    : ''
                )
        );

        if (empty($permissions) && empty($roles)) {
            // Check if the command is running in console then prompt the user to confirm.
            if (app()->runningInConsole() && $this->confirm($conf)) {
                $roles = Role::whereNotIn(
                    'name',
                    ! $supes ? [config('permission-defs.super-admin-role', 'super-admin')] : []
                )->pluck('name');
            } else {
                $this->error('No roles or permissions were specified. Exiting...');

                return;
            }
        } elseif (! empty($roles)) {
            $roles = $this->names(Role::class, $roles);
        }

        if (! empty($permissions)) {
            $permissions = $this->names(Permission::class, $permissions);
        } else {
            $permissions = collect();
        }

        $users->each(function ($user) use ($roles, $permissions) {
            $roles->each(fn($role) => $user->removeRole($role));
            $permissions->each(fn($permission) => $user->revokePermissionTo($permission));
        });

        $this->info('Roles');
        $this->table(
            ['ID', 'Name', 'Gaurds', 'Roles'],
            $users->map(fn($user) => [
                $user->id,
                $user->first_name,
                $user->roles->pluck('guard_name')->implode(', '),
                $user->roles->pluck('name')->implode(', '),
            ])
        );

        $this->newLine();
        $this->info('Permissions');
        $this->table(
            ['ID', 'Name', 'Gaurds', 'Permissions'],
            $users->map(fn($user) => [
                $user->id,
                $user->first_name,
                $user->roles->pluck('guard_name')->implode(', '),
                $user->roles->pluck('permissions')->implode(', '),
            ])
        );
    }

    /**
     * Sync the roles and permissions to the specified user(s).
     */
    public function sync()
    {
        $users = $this->argument('users');
        $roles = $this->option('roles');
        $supes = $this->option('supes');
        $permissions = $this->option('permissions');

        /** @var Collection<TKey,User> */
        $users = app(config('permission-defs.user-model', []))->findMany($users);

        $conf = str_ireplace(
            ["\n", "\t", '  '],
            ["\n ", '', ''],
            'You have not specified any roles or permissions.
            Do you want to assign all roles to the user(s) ' .
                (! $supes
                    ? '(This excludes the "' . config('permission-defs.super-admin-role', 'super-admin') . ' role)?'
                    : ''
                )
        );

        if (empty($permissions) && empty($roles)) {
            // Check if the command is running in console then prompt the user to confirm.
            if (app()->runningInConsole() && $this->confirm($conf)) {
                $roles = Role::whereNotIn(
                    'name',
                    ! $supes ? [config('permission-defs.super-admin-role', 'super-admin')] : []
                )->pluck('name');
            } else {
                $this->error('No roles or permissions were specified. Exiting...');

                return;
            }
        } elseif (! empty($roles)) {
            $roles = $this->names(Role::class, $roles);
        }

        if (! empty($permissions)) {
            $permissions = $this->names(Permission::class, $permissions);
        }

        $users->each(function ($user) use ($roles, $permissions) {
            $user->syncRoles($roles);
            $user->syncPermissions($permissions);
        });

        $this->info('Roles');
        $this->table(
            ['ID', 'Name', 'Gaurds', 'Roles'],
            $users->map(fn($user) => [
                $user->id,
                $user->first_name,
                $user->roles->pluck('guard_name')->implode(', '),
                $user->roles->pluck('name')->implode(', '),
            ])
        );

        $this->newLine();
        $this->info('Permissions');
        $this->table(
            ['ID', 'Name', 'Gaurds', 'Permissions'],
            $users->map(fn($user) => [
                $user->id,
                $user->first_name,
                $user->roles->pluck('guard_name')->implode(', '),
                $user->roles->pluck('permissions')->implode(', '),
            ])
        );
    }

    public function truncate(): void
    {
        if (app()->runningInConsole() && $this->confirm('Are you sure you want to reset all permissions and roles?')) {
            // Works on PostgreSQL, MySQL/MariaDB and SQLite
            Schema::withoutForeignKeyConstraints(function () {
                Role::query()->delete();
                Permission::query()->delete();
            });
        }
    }

    /**
     * Names of the roles or permissions given by name or id.
     *
     * @param  class-string<Role|Permission>  $model
     * @param  array<int, string>  $values
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function names(string $model, array $values)
    {
        // Ids and names are matched separately, so PostgreSQL never compares a name with the bigint id.
        [$ids, $names] = collect($values)->partition(fn($value) => ctype_digit((string) $value));

        return $model::query()
            ->where(fn($query) => $query->whereIn('name', $names->all())->orWhereIn('id', $ids->all()))
            ->pluck('name');
    }

    /**
     * Run the database seeds.
     */
    public function make()
    {
        $rolesArray = collect(config('permission-defs.roles', []));
        $permissionsArray = collect(config('permission-defs.permissions', []));

        $rolesArray->each(fn($role) => Role::findOrCreate($role));
        $permissionsArray->each(fn($role) => Permission::findOrCreate($role));

        $exclusions = (array) config('permission-defs.exclusions', []);

        // Every role gets every permission except the ones excluded for it.
        Role::query()->whereIn('name', $rolesArray)->get()->each(
            fn(Role $role) => $role->syncPermissions($permissionsArray->diff($exclusions[$role->name] ?? [])->values())
        );

        $roles = Role::withCount('permissions')->get();
        $permissions = Permission::get();

        $this->info('Roles');
        $this->table(
            ['ID', 'Name', 'Gaurd', 'Permissions'],
            $roles->map(fn($role) => $role->only('id', 'name', 'guard_name', 'permissions_count'))
        );

        $this->newLine();
        $this->info('Permissions');
        $this->table(
            ['ID', 'Name', 'Gaurd'],
            $permissions->map(fn($perm) => $perm->only('id', 'name', 'guard_name'))
        );
    }
}
