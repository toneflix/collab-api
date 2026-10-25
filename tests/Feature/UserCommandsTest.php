<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage-users', 'manage-admins'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (['admin', 'super-admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
    }

    public function test_users_are_listed_a_page_at_a_time(): void
    {
        $tag = 'cmdlist' . uniqid();
        User::factory()->count(3)->sequence(fn($s) => ['username' => "{$tag}{$s->index}"])->create();

        $this->artisan('app:users', ['--search' => $tag, '--per-page' => 2, '--no-interaction' => true])
            ->expectsOutputToContain("{$tag}2")
            ->expectsOutputToContain('Page 1 of 2, 3 users.')
            ->doesntExpectOutputToContain("{$tag}0")
            ->assertSuccessful();

        $this->artisan('app:users', ['--search' => $tag, '--per-page' => 2, '--page' => 2, '--no-interaction' => true])
            ->expectsOutputToContain("{$tag}0")
            ->expectsOutputToContain('Page 2 of 2, 3 users.')
            ->assertSuccessful();
    }

    public function test_the_next_page_is_offered_when_interactive(): void
    {
        $tag = 'cmdnext' . uniqid();
        User::factory()->count(2)->sequence(fn($s) => ['username' => "{$tag}{$s->index}"])->create();

        $this->artisan('app:users', ['--search' => $tag, '--per-page' => 1])
            ->expectsConfirmation('Show the next page?', 'yes')
            ->expectsOutputToContain('Page 2 of 2, 2 users.')
            ->assertSuccessful();
    }

    public function test_users_are_filtered_by_role_and_permission(): void
    {
        $tag = 'cmdrole' . uniqid();
        $admin = User::factory()->create(['username' => "{$tag}admin"])->assignRole('admin');
        $manager = User::factory()->create(['username' => "{$tag}manager"]);
        $manager->givePermissionTo('manage-users');
        User::factory()->create(['username' => "{$tag}plain"]);

        $this->artisan('app:users', ['--search' => $tag, '--role' => 'admin', '--no-interaction' => true])
            ->expectsOutputToContain($admin->username)
            ->doesntExpectOutputToContain("{$tag}plain")
            ->assertSuccessful();

        $this->artisan('app:users', ['--search' => $tag, '--permission' => 'manage-users', '--no-interaction' => true])
            ->expectsOutputToContain($manager->username)
            ->doesntExpectOutputToContain("{$tag}plain")
            ->assertSuccessful();
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->artisan('app:users', ['--status' => 'nope'])->assertExitCode(2);
    }

    public function test_roles_and_permissions_are_granted_and_revoked_and_logged(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:user-access', [
            'user' => $user->email,
            '--grant-role' => ['admin', (string) Role::findByName('super-admin', 'web')->id],
            '--grant-permission' => ['manage-users'],
        ])->assertSuccessful();

        $user = $user->fresh();
        $this->assertEqualsCanonicalizing(['admin', 'super-admin'], $user->getRoleNames()->all());
        $this->assertSame(['manage-users'], $user->getDirectPermissions()->pluck('name')->all());

        $this->artisan('app:user-access', [
            'user' => $user->username,
            '--revoke-role' => ['super-admin'],
            '--revoke-permission' => ['manage-users'],
        ])->assertSuccessful();

        $user = $user->fresh();
        $this->assertSame(['admin'], $user->getRoleNames()->all());
        $this->assertCount(0, $user->getDirectPermissions());

        $actions = AdminAction::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame(['roles.changed', 'permissions.changed', 'roles.changed', 'permissions.changed'], $actions->pluck('action')->all());
        $this->assertTrue($actions->every(fn($a) => $a->admin_id === null && $a->details['via'] === 'console'));
    }

    public function test_unknown_roles_change_nothing(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:user-access', ['user' => (string) $user->id, '--grant-role' => ['admin', 'wizard']])
            ->expectsOutputToContain('Unknown role: wizard')
            ->assertFailed();

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_an_unknown_user_is_reported(): void
    {
        $this->artisan('app:user-access', ['user' => 'nobody-' . uniqid()])->assertFailed();
    }
}
