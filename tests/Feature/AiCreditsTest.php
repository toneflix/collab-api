<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiCreditEntry;
use App\Models\Configuration;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Payment\Checkout;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiCreditsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public array $initialized = [];

            public function initialize(string $email, float $amount, string $reference, array $metadata = []): array
            {
                $this->initialized[$reference] = $amount;

                return ['reference' => $reference, 'access_code' => 'code', 'authorization_url' => 'https://checkout.test'];
            }

            public function verify(string $reference): array
            {
                return ['final' => true, 'paid' => true, 'amount' => $this->initialized[$reference] ?? 0, 'currency' => 'USD', 'raw' => []];
            }

            public function validWebhook(string $payload, ?string $signature): bool
            {
                return true;
            }
        });

        AiSettings::update([
            'ai_enabled' => true,
            'ai_credit_price' => 0.02,
            'ai_credit_packs' => '100, 500:10',
        ]);
    }

    /**
     * A user on a plan that includes AI tools and this many credits a month.
     */
    protected function member(int $credits = 50, bool $tools = true): User
    {
        $plan = Plan::create([
            'slug' => 'ai-'.uniqid(), 'name' => 'AI', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => $tools, Feature::AI_CREDITS->value => $credits],
        ]);

        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
        ]);

        return $user->fresh();
    }

    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        Sanctum::actingAs($user = $user->fresh());

        return $user;
    }

    public function test_plans_can_grant_ai_tools_and_monthly_credits(): void
    {
        $catalogue = collect(Feature::catalogue())->keyBy('key');

        $this->assertSame('toggle', $catalogue['ai_tools']['type']);
        $this->assertSame('credits', $catalogue['ai_credits']['type']);
        $this->assertFalse(Plan::default()->feature(Feature::AI_TOOLS));
        $this->assertSame(0, Plan::default()->feature(Feature::AI_CREDITS));

        $premium = Plan::where('slug', 'premium')->first();
        $this->assertTrue($premium->feature(Feature::AI_TOOLS));
        $this->assertSame(100, $premium->feature(Feature::AI_CREDITS));
    }

    public function test_the_migration_grants_premium_on_a_fresh_install_but_keeps_admin_choices(): void
    {
        $premium = Plan::where('slug', 'premium')->first();
        $migration = require database_path('migrations/2026_10_29_000001_create_ai_credits_tables.php');
        $grant = fn () => (fn () => $this->grantPremium())->call($migration);

        // A fresh install already holds the off defaults from the plans migration.
        $premium->forceFill(['features' => [...$premium->features, 'ai_tools' => false, 'ai_credits' => 0]])->save();
        $grant();
        $premium->refresh();
        $this->assertTrue($premium->feature(Feature::AI_TOOLS));
        $this->assertSame(100, $premium->feature(Feature::AI_CREDITS));

        $premium->forceFill(['features' => [...$premium->features, 'ai_credits' => 250]])->save();
        $grant();
        $this->assertSame(250, $premium->refresh()->feature(Feature::AI_CREDITS));
    }

    public function test_plan_credits_are_used_first_and_reset_each_month(): void
    {
        $user = $this->member(50);
        $credits = Credits::for($user);
        $credits->add(30, 'Bought');

        $credits->spend(60, 'Song');

        $this->assertSame(['planLeft' => 0, 'purchased' => 20, 'total' => 20], collect($credits->balance())->only('planLeft', 'purchased', 'total')->all());

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());

        $this->assertSame(['planLeft' => 50, 'purchased' => 20, 'total' => 70], collect(Credits::for($user)->balance())->only('planLeft', 'purchased', 'total')->all());
    }

    public function test_spending_more_than_the_balance_is_refused(): void
    {
        $user = $this->member(10);

        $this->expectException(ValidationException::class);

        Credits::for($user)->spend(11, 'Song');
    }

    public function test_a_refund_puts_credits_back_where_they_came_from_once(): void
    {
        $user = $this->member(10);
        $credits = Credits::for($user);
        $credits->add(20, 'Bought');

        $spend = $credits->spend(15, 'Song');
        $this->assertSame([10, 5], [$spend->plan_credits, $spend->purchased_credits]);

        $credits->refund($spend);
        $this->assertNull($credits->refund($spend));

        $this->assertSame(['planLeft' => 10, 'purchased' => 20], collect($credits->balance())->only('planLeft', 'purchased')->all());
        $this->assertSame(1, AiCreditEntry::where('refund_of', $spend->id)->count());
    }

    public function test_ai_tools_need_a_plan_that_includes_them_and_admins_switching_them_on(): void
    {
        Credits::for($this->member())->ensureAccess();

        try {
            Credits::for($this->member(50, false))->ensureAccess();
            $this->fail('A plan without AI tools was let in.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('AI studio tools', $e->getMessage());
        }

        AiSettings::update(['ai_enabled' => false]);

        $this->expectException(ValidationException::class);
        Credits::for($this->member())->ensureAccess();
    }

    public function test_buying_a_pack_adds_credits_once(): void
    {
        $user = $this->actingAsFresh($this->member(0));

        $reference = $this->postJson('/api/payments', ['purpose' => 'credits', 'credits' => 500])
            ->assertCreated()
            ->assertJsonPath('data.amount', 9)
            ->json('data.reference');

        $this->postJson("/api/payments/{$reference}/verify")->assertOk();
        app(Checkout::class)->verify(Transaction::where('reference', $reference)->first());

        $this->assertSame(500, Credits::for($user)->balance()['purchased']);
        $this->assertSame(1, AiCreditEntry::where('user_id', $user->id)->where('type', 'purchase')->count());

        $this->postJson('/api/payments', ['purpose' => 'credits', 'credits' => 123])->assertJsonValidationErrors('credits');
    }

    public function test_people_without_ai_tools_cannot_buy_credits(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $this->postJson('/api/payments', ['purpose' => 'credits', 'credits' => 100])->assertJsonValidationErrors('credits');
    }

    public function test_the_account_shows_the_balance_packs_and_history(): void
    {
        $user = $this->actingAsFresh($this->member(50));
        Credits::for($user)->spend(5, 'Song');

        $this->getJson('/api/account/ai')
            ->assertOk()
            ->assertJsonPath('data.access', true)
            ->assertJsonPath('data.balance.total', 45)
            ->assertJsonPath('data.packs.1.price', 9)
            ->assertJsonPath('data.costs.stems', 5);

        $this->getJson('/api/account/ai/history')
            ->assertOk()
            ->assertJsonPath('data.0.credits', -5)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_credit_price_follows_the_app_currency_until_set(): void
    {
        Configuration::where('key', 'ai_credit_price')->delete();
        Configuration::where('key', 'app_currency')->first()->update(['value' => 'NGN']);
        AiSettings::flush();

        $this->assertSame(30.0, AiSettings::creditPrice());
    }

    public function test_admins_change_ai_settings(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('manage-configuration');

        $this->actingAsFresh(User::factory()->create());
        $this->getJson('/api/admin/ai')->assertForbidden();

        $this->actingAsFresh($admin);
        $this->putJson('/api/admin/ai', ['ai_credit_packs' => 'lots'])->assertJsonValidationErrors('ai_credit_packs');
        $this->putJson('/api/admin/ai', ['ai_credit_packs' => '50, 1000:25', 'ai_stems_credits' => 8])
            ->assertAccepted()
            ->assertJsonPath('data.client.packs.1.price', 15)
            ->assertJsonPath('data.client.costs.stems', 8);
    }
}
