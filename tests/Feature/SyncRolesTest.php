<?php

namespace Tests\Feature;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncRolesTest extends TestCase
{
    public function test_roles_get_every_permission_except_their_exclusions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->artisan('app:sync-roles')->assertSuccessful();

        $permissions = fn (string $role) => Role::findByName($role, 'web')->permissions->pluck('name')->sort()->values()->all();
        $all = collect(config('permission-defs.permissions'))->sort()->values();

        $this->assertSame($all->all(), $permissions('super-admin'));
        $this->assertSame($all->diff(['manage-admins'])->values()->all(), $permissions('admin'));
        $this->assertSame($all->diff(['manage-plans', 'manage-payouts'])->values()->all(), $permissions('support'));
        $this->assertNotContains('manage-content', $permissions('user'));
    }
}
