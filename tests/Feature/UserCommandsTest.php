<?php

namespace Tests\Feature;

use App\Console\Users\UserMenu;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\Admin\UserManager;
use App\Services\Auth\Totp;
use App\Services\Auth\TwoFactor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
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
        User::factory()
            ->count(3)
            ->sequence(fn($s) => ['username' => "{$tag}{$s->index}"])
            ->create();

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

    public function test_the_list_pages_with_previous_and_next_and_searches(): void
    {
        $tag = 'cmdnext' . uniqid();
        User::factory()
            ->count(2)
            ->sequence(fn($s) => ['username' => "{$tag}{$s->index}"])
            ->create();

        $newest = User::query()
            ->where('username', "{$tag}1")
            ->first();

        $this->artisan('list:users', ['--search' => $tag, '--per-page' => 1])
            ->expectsChoice("Users matching \"{$tag}\", page 1 of 2 (2 in all)", 'next', [
                "#{$newest->id}" => UserMenu::line($newest),
                'next' => 'Next page →',
                'search' => 'Change the search',
                'quit' => 'Quit',
            ])
            ->expectsQuestion("Users matching \"{$tag}\", page 2 of 2 (2 in all)", 'previous')
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 2 (2 in all)", 'search')
            ->expectsQuestion('Search by id, username, email or name', "{$tag}0")
            ->expectsQuestion("Users matching \"{$tag}0\", page 1 of 1 (1 in all)", 'quit')
            ->assertSuccessful();
    }

    public function test_two_factor_sign_in_is_reset_from_the_user_menu(): void
    {
        $tag = 'cmd2fa' . uniqid();
        $user = User::factory()
            ->create(['username' => $tag]);
        $user
            ->forceFill(['two_factor_secret' => Totp::secret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x']])
            ->save();

        $this->artisan('app:users', ['--search' => $tag])
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 1 (1 in all)", "#{$user->id}")
            ->expectsQuestion("What do you want to do with @{$tag}?", 'ResetTwoFactor')
            ->expectsQuestion('Reason', 'Lost their phone')
            ->expectsConfirmation("Turn two-factor sign-in off for @{$tag}?", 'yes')
            ->expectsOutputToContain('Two-factor sign-in is off for this account.')
            ->expectsQuestion("What do you want to do with @{$tag}?", 'back')
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 1 (1 in all)", 'quit')
            ->assertSuccessful();

        $this->assertFalse(app(TwoFactor::class)->enabled($user->fresh()));

        $action = AdminAction::query()
            ->where('user_id', $user->id)
            ->sole();
        $this->assertSame('two_factor.reset', $action->action);
        $this->assertSame('Lost their phone', $action->reason);
        $this->assertNull($action->admin_id);
        $this->assertSame('console', $action->details['via']);
    }

    public function test_an_account_is_suspended_and_restored_from_the_user_menu(): void
    {
        $tag = 'cmdsus' . uniqid();
        $user = User::factory()
            ->create(['username' => $tag]);

        $this->artisan('app:users', ['--search' => $tag])
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 1 (1 in all)", "#{$user->id}")
            ->expectsQuestion("What do you want to do with @{$tag}?", 'SuspendAccount')
            ->expectsQuestion('Suspend for how many days?', '3')
            ->expectsQuestion('Reason', 'Spam')
            ->expectsConfirmation('Suspend @' . $tag . ' until ' . now()->addDays(3)->toDayDateTimeString() . '?', 'yes')
            ->expectsQuestion("What do you want to do with @{$tag}?", 'RestoreAccount')
            ->expectsQuestion('Reason (optional)', '')
            ->expectsOutputToContain("@{$tag} can sign in again.")
            ->expectsQuestion("What do you want to do with @{$tag}?", 'back')
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 1 (1 in all)", 'quit')
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->isSuspended());

        $actions = AdminAction::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->pluck('action');
        $this->assertSame(['account.suspended', 'account.restored'], $actions->all());
    }

    public function test_the_password_is_changed_from_the_user_menu(): void
    {
        $tag = 'cmdpass' . uniqid();
        $user = User::factory()
            ->create(['username' => $tag]);
        $user->createToken('phone');

        $this->artisan('app:users', ['--search' => $tag])
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 1 (1 in all)", "#{$user->id}")
            ->expectsQuestion("What do you want to do with @{$tag}?", 'ChangePassword')
            ->expectsQuestion('New password', 'new-secret-123')
            ->expectsQuestion('Type it again', 'new-secret-123')
            ->expectsConfirmation('Sign them out on every device too?', 'yes')
            ->expectsQuestion('Reason (optional)', 'Owner asked by phone')
            ->expectsConfirmation("Change the password of @{$tag}?", 'yes')
            ->expectsOutputToContain("The password of @{$tag} was changed.")
            ->expectsQuestion("What do you want to do with @{$tag}?", 'back')
            ->expectsQuestion("Users matching \"{$tag}\", page 1 of 1 (1 in all)", 'quit')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-123', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $action = AdminAction::query()
            ->where('user_id', $user->id)
            ->sole();
        $this->assertSame('password.changed', $action->action);
        $this->assertSame('Owner asked by phone', $action->reason);
        $this->assertSame(1, $action->details['sessions']);
    }

    public function test_a_password_that_breaks_the_rules_is_refused(): void
    {
        $user = User::factory()
            ->create();
        $hash = $user->password;

        try {
            app(UserManager::class)->changePassword(null, $user, 'short');
            $this->fail('A short password was accepted.');
        } catch (ValidationException) {
            $this->assertSame($hash, $user->fresh()->password);
        }
    }

    public function test_two_factor_cannot_be_reset_when_it_is_off(): void
    {
        $user = User::factory()
            ->create();

        $this->expectException(ValidationException::class);

        app(UserManager::class)->resetTwoFactor(null, $user);
    }

    public function test_users_are_filtered_by_role_and_permission(): void
    {
        $tag = 'cmdrole' . uniqid();
        $admin = User::factory()
            ->create(['username' => "{$tag}admin"])
            ->assignRole('admin');
        $manager = User::factory()
            ->create(['username' => "{$tag}manager"]);
        $manager->givePermissionTo('manage-users');
        User::factory()
            ->create(['username' => "{$tag}plain"]);

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
        $this
            ->artisan('app:users', ['--status' => 'nope'])
            ->assertExitCode(2);
    }

    public function test_roles_and_permissions_are_granted_and_revoked_and_logged(): void
    {
        $user = User::factory()
            ->create();

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

        $actions = AdminAction::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();
        $this->assertSame(['roles.changed', 'permissions.changed', 'roles.changed', 'permissions.changed'], $actions->pluck('action')->all());
        $this->assertTrue($actions->every(fn($a) => $a->admin_id === null && $a->details['via'] === 'console'));
    }

    public function test_unknown_roles_change_nothing(): void
    {
        $user = User::factory()
            ->create();

        $this->artisan('app:user-access', ['user' => (string) $user->id, '--grant-role' => ['admin', 'wizard']])
            ->expectsOutputToContain('Unknown role: wizard')
            ->assertFailed();

        $this->assertCount(0, $user->fresh()->roles);
    }

    public function test_an_unknown_user_is_reported(): void
    {
        $this
            ->artisan('app:user-access', ['user' => 'nobody-' . uniqid()])
            ->assertFailed();
    }
}
