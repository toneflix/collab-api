<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncRolesTest extends TestCase
{
    public function test_roles_get_every_permission_except_their_exclusions(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $this
            ->artisan('app:sync-roles')
            ->assertSuccessful();

        $permissions = fn(string $role) => Role::findByName($role, 'web')->permissions->pluck('name')->sort()->values()->all();
        $all = collect(config('permission-defs.permissions'))
            ->sort()
            ->values();

        $this->assertSame($all->all(), $permissions('super-admin'));
        $this->assertSame($all->diff(['manage-admins'])->values()->all(), $permissions('admin'));
        $this->assertSame($all->diff(['manage-admins', 'manage-plans', 'manage-payouts'])->values()->all(), $permissions('support'));
        $this->assertSame([], $permissions('user'));
    }

    public function test_roles_are_given_by_name_or_id(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        $this
            ->artisan('app:sync-roles')
            ->assertSuccessful();

        $user = User::factory()
            ->create();
        $support = Role::findByName('support', 'web');

        $this
            ->artisan('app:sync-roles', ['users' => [$user->id], '--roles' => ['admin', (string) $support->id]])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['admin', 'support'], $user->fresh()->getRoleNames()->all());
    }
}
