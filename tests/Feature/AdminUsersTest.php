<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Credits;
use App\Services\Payment\Payouts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        foreach (['manage-users', 'manage-admins', 'manage-configuration', 'manage-plans', 'manage-payouts', 'manage-content'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (['admin', 'super-admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }
    }

    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function adminWith(string ...$permissions): User
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo($permissions);

        return $this->actingAsFresh($admin);
    }

    protected function earning(User $creator, float $share): Transaction
    {
        $payer = User::factory()
            ->create();
        $transaction = new Transaction([
            'reference' => 'TRX-' . uniqid(),
            'purpose' => 'tip',
            'method' => 'paystack',
            'status' => 'complete',
            'amount' => $share,
            'due' => $share,
            'recipient_id' => $creator->id,
            'recipient_share' => $share,
            'paid_at' => now()->subDays(30),
        ]);
        $transaction->user_id = $payer->id;
        $transaction
            ->transactable()
            ->associate($creator);
        $transaction->save();

        return $transaction;
    }

    public function test_only_user_managers_see_users(): void
    {
        $this->adminWith('manage-content');

        $this
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_the_list_is_paginated_and_searchable(): void
    {
        $this->adminWith('manage-users');
        $match = User::factory()
            ->create(['username' => 'findme_' . uniqid()]);

        $this->getJson('/api/admin/users?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'username', 'email', 'roles', 'plan', 'suspended']], 'meta' => ['lastPage'], 'roles']);

        $this->getJson('/api/admin/users?search=findme_')
            ->assertOk()
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_the_user_page_shows_stats_and_what_the_admin_can_do(): void
    {
        $this->adminWith('manage-users');
        $user = User::factory()
            ->create();
        $this->earning($user, 500);

        $this->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('stats.earnings.available', 500)
            ->assertJsonPath('can.suspend', true)
            ->assertJsonPath('can.funds', false)
            ->assertJsonPath('can.plan', false)
            ->assertJsonStructure(['stats' => ['content', 'plan', 'earnings', 'spend', 'ai' => ['balance', 'used'], 'storage']]);
    }

    public function test_a_suspended_user_is_signed_out_and_cant_sign_in(): void
    {
        $user = User::factory()
            ->create(['password' => 'secret-pass']);
        $user->createToken('device');

        $admin = $this->adminWith('manage-users');
        $this->postJson("/api/admin/users/{$user->id}/suspend", ['days' => 7, 'reason' => 'Spam'])
            ->assertOk()
            ->assertJsonPath('data.suspended', true);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(AdminAction::where('user_id', $user->id)->where('action', 'account.suspended')->where('admin_id', $admin->id)->exists());

        $this->actingAsFresh($user);
        $this
            ->getJson('/api/account')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertUnprocessable();

        $this->actingAsFresh($admin);
        $this
            ->postJson("/api/admin/users/{$user->id}/unsuspend")
            ->assertOk()
            ->assertJsonPath('data.suspended', false);
    }

    public function test_admins_cant_suspend_themselves_or_other_staff_without_managing_admins(): void
    {
        $admin = $this->adminWith('manage-users');
        $staff = User::factory()
            ->create();
        $staff->givePermissionTo('manage-content');

        $this
            ->postJson("/api/admin/users/{$admin->id}/suspend", ['reason' => 'x'])
            ->assertForbidden();
        $this
            ->postJson("/api/admin/users/{$staff->id}/suspend", ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_only_super_admins_give_the_super_admin_role(): void
    {
        $admin = $this->adminWith('manage-users', 'manage-admins');
        $user = User::factory()
            ->create();

        $this->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['admin']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['admin']);

        $this
            ->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['super-admin']])
            ->assertUnprocessable();

        $admin->assignRole('super-admin');
        $this->actingAsFresh($admin);
        $this
            ->putJson("/api/admin/users/{$user->id}/roles", ['roles' => ['super-admin']])
            ->assertOk();
    }

    public function test_an_admin_puts_a_user_on_a_plan_and_takes_it_away(): void
    {
        $this->adminWith('manage-users', 'manage-plans');
        $user = User::factory()
            ->create();
        $plan = Plan::create(['name' => 'Gift', 'slug' => 'gift-' . uniqid(), 'price' => 1000, 'interval' => 'month']);

        $this->postJson("/api/admin/users/{$user->id}/plan", ['plan_id' => $plan->id, 'cycles' => 3])
            ->assertOk()
            ->assertJsonPath('data.plan.id', $plan->id);

        $subscription = $user
            ->subscriptions()
            ->active()
            ->first();
        $this->assertSame(0.0, $subscription->amount);
        $this->assertTrue($subscription->ends_at->between(now()->addMonths(3)->subMinute(), now()->addMonths(3)->addMinute()));

        $this
            ->deleteJson("/api/admin/users/{$user->id}/plan")
            ->assertOk();
        $this->assertFalse($user->subscriptions()->active()->exists());
        $this->assertSame(['plan.assigned', 'plan.removed'], $user->adminActions()->reorder('id')->pluck('action')->all());
    }

    public function test_funds_change_the_withdrawable_balance_but_not_revenue(): void
    {
        $this->adminWith('manage-users', 'manage-payouts');
        $user = User::factory()
            ->create();
        $this->earning($user, 100);

        $this
            ->postJson("/api/admin/users/{$user->id}/funds", ['amount' => 250, 'reason' => 'Contest prize'])
            ->assertOk();
        $this->assertSame(350.0, app(Payouts::class)->summary($user)['available']);

        $this
            ->postJson("/api/admin/users/{$user->id}/funds", ['amount' => -1000, 'reason' => 'Too much'])
            ->assertUnprocessable();
        $this
            ->postJson("/api/admin/users/{$user->id}/funds", ['amount' => -50, 'reason' => 'Correction'])
            ->assertOk();
        $this->assertSame(300.0, app(Payouts::class)->summary($user)['available']);

        $this->getJson("/api/admin/users/{$user->id}/transactions?side=earnings")
            ->assertOk()
            ->assertJsonPath('data.0.purpose', Payouts::ADJUSTMENT)
            ->assertJsonPath('data.0.earned', -50);
    }

    public function test_credits_are_granted_and_taken_back(): void
    {
        $this->adminWith('manage-users');
        $user = User::factory()
            ->create();

        $this
            ->postJson("/api/admin/users/{$user->id}/credits", ['credits' => 40, 'reason' => 'Beta tester'])
            ->assertOk();
        $this->assertSame(40, Credits::for($user)->balance()['purchased']);

        $this
            ->postJson("/api/admin/users/{$user->id}/credits", ['credits' => -50, 'reason' => 'Oops'])
            ->assertUnprocessable();
        $this
            ->postJson("/api/admin/users/{$user->id}/credits", ['credits' => -15, 'reason' => 'Oops'])
            ->assertOk();
        $this->assertSame(25, Credits::for($user)->balance()['purchased']);

        $this->getJson("/api/admin/users/{$user->id}/activity")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'credits.removed')
            ->assertJsonPath('data.0.details.credits', 15)
            ->assertJsonPath('data.1.action', 'credits.added');
    }

    public function test_profile_changes_are_recorded(): void
    {
        $this->adminWith('manage-users');
        $user = User::factory()
            ->create(['firstname' => 'Old', 'verified' => false]);

        $this
            ->putJson("/api/admin/users/{$user->id}", ['firstname' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.firstname', 'New');
        $this
            ->postJson("/api/admin/users/{$user->id}/verify", ['verified' => true])
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->getJson("/api/admin/users/{$user->id}/activity")
            ->assertJsonPath('data.1.details.changes.firstname', ['from' => 'Old', 'to' => 'New'])
            ->assertJsonPath('data.0.action', 'account.verified');
    }
}
