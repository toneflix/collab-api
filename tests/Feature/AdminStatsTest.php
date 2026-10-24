<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use DatabaseTransactions;

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
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage-configuration', 'manage-plans', 'manage-payouts', 'manage-content'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = User::factory()->create();
        $admin->givePermissionTo($permissions);

        return $this->actingAsFresh($admin);
    }

    protected function payment(float $amount, ?float $share = null): Transaction
    {
        $transaction = new Transaction([
            'reference' => 'TRX-'.uniqid(),
            'purpose' => $share ? 'release' : 'premium',
            'method' => 'paystack',
            'status' => 'complete',
            'amount' => $amount,
            'due' => $amount,
            'recipient_share' => $share ?? 0,
            'paid_at' => now(),
        ]);
        $payer = User::factory()->create();
        $transaction->user_id = $payer->id;
        $transaction->transactable()->associate($payer);
        $transaction->save();

        return $transaction;
    }

    public function test_only_admins_see_the_stats(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $this->getJson('/api/admin/stats')->assertForbidden();
    }

    public function test_admins_see_people_content_and_the_sections_they_manage(): void
    {
        $this->adminWith('manage-content');

        $this->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['days', 'currency', 'people' => ['users', 'new', 'subscribers', 'daily'], 'content', 'moderation' => ['pending', 'appeals']]])
            ->assertJsonCount(30, 'data.people.daily')
            ->assertJsonMissingPath('data.revenue')
            ->assertJsonMissingPath('data.payouts');
    }

    public function test_revenue_counts_completed_payments_and_what_the_platform_keeps(): void
    {
        $before = Transaction::query()->where('status', 'complete')->sum('amount');
        $this->adminWith('manage-payouts');

        $this->payment(1000);
        $this->payment(2000, 1700);

        $response = $this->getJson('/api/admin/stats')->assertOk()->assertJsonStructure(['data' => ['revenue', 'payouts']]);

        $this->assertEqualsWithDelta($before + 3000, $response->json('data.revenue.total'), 0.01);
        $this->assertEqualsWithDelta(3000, collect($response->json('data.revenue.daily'))->last()['value'], 0.01);
        $this->assertContains('release', array_column($response->json('data.revenue.byPurpose'), 'purpose'));
    }
}
