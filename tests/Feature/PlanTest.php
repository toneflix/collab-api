<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Subscription;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('manage-plans', 'web');

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage-plans');

        return $this->actingAsFresh($admin);
    }

    /**
     * Controllers keep the user they were built with, so drop them between users.
     */
    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function setFeatures(Plan $plan, array $features): Plan
    {
        $plan->features = [...($plan->features ?? []), ...$features];
        $plan->save();

        return $plan;
    }

    public function test_anyone_can_see_the_plans_and_the_catalogue(): void
    {
        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonCount(count(Feature::cases()), 'data.features')
            ->assertJsonStructure(['data' => ['plans' => [['id', 'slug', 'name', 'price', 'interval', 'features', 'isDefault']]]]);

        $this->actingAsFresh(User::factory()->create());

        $this->getJson('/api/plans')->assertOk()->assertJsonPath('data.current.slug', Plan::default()->slug);
    }

    public function test_only_plan_managers_can_manage_plans(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $this->getJson('/api/admin/plans')->assertForbidden();
        $this->postJson('/api/admin/plans', ['name' => 'Pro'])->assertForbidden();

        $this->admin();

        $this->getJson('/api/admin/plans')->assertOk();
        $this->getJson('/api/admin/plans/features')->assertOk()->assertJsonPath('data.0.key', 'downloads');
    }

    public function test_admins_create_plans_that_choose_their_features(): void
    {
        $this->admin();

        $response = $this->postJson('/api/admin/plans', [
            'name' => 'Studio Pro',
            'price' => 5000,
            'interval' => 'month',
            'interval_count' => 3,
            'features' => ['downloads' => true, 'max_projects' => 50, 'not_a_feature' => true],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'studio-pro')
            ->assertJsonPath('data.features.downloads', true)
            ->assertJsonPath('data.features.max_projects', 50)
            // Features the admin left out get their catalogue default
            ->assertJsonPath('data.features.badge', false)
            ->assertJsonMissingPath('data.features.not_a_feature');

        $plan = Plan::findOrFail($response->json('data.id'));
        $this->assertSame('3 month', $plan->period());

        $this->putJson("/api/admin/plans/{$plan->id}", ['features' => ['badge' => true]])
            ->assertAccepted()
            ->assertJsonPath('data.features.badge', true)
            ->assertJsonPath('data.features.max_projects', 50);

        $this->postJson('/api/admin/plans', ['name' => 'Broken', 'price' => -1, 'interval' => 'fortnight'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'interval']);
    }

    public function test_admins_set_bulk_billing_options_with_discounts(): void
    {
        $this->admin();

        $plan = Plan::where('slug', 'premium')->firstOrFail();
        $plan->update(['price' => 1000, 'interval' => 'month']);

        $this->putJson("/api/admin/plans/{$plan->id}", [
            'billing_options' => [['cycles' => 12, 'discount' => 25], ['cycles' => 6, 'discount' => 10]],
        ])->assertAccepted()
            // Paying for one month at full price is always offered, then the options in order.
            ->assertJsonCount(3, 'data.billingOptions')
            ->assertJsonPath('data.billingOptions.0.cycles', 1)
            ->assertJsonPath('data.billingOptions.0.price', 1000)
            ->assertJsonPath('data.billingOptions.1.cycles', 6)
            ->assertJsonPath('data.billingOptions.1.price', 5400)
            ->assertJsonPath('data.billingOptions.2.cycles', 12)
            ->assertJsonPath('data.billingOptions.2.price', 9000)
            ->assertJsonPath('data.billingOptions.2.perCycle', 750)
            ->assertJsonPath('data.billingOptions.2.saved', 3000);

        $this->putJson("/api/admin/plans/{$plan->id}", [
            'billing_options' => [['cycles' => 1, 'discount' => 5], ['cycles' => 6, 'discount' => 120], ['cycles' => 6, 'discount' => 10]],
        ])->assertJsonValidationErrors(['billing_options.0.cycles', 'billing_options.1.discount', 'billing_options.2.cycles']);

        // Removing every option leaves monthly payments only.
        $this->putJson("/api/admin/plans/{$plan->id}", ['billing_options' => []])
            ->assertAccepted()
            ->assertJsonCount(1, 'data.billingOptions');
    }

    public function test_there_is_always_one_free_default_plan(): void
    {
        $this->admin();
        $free = Plan::default();

        $this->postJson('/api/admin/plans', ['name' => 'Paid default', 'price' => 100, 'interval' => 'month', 'is_default' => true])
            ->assertJsonValidationErrors('is_default');

        $this->putJson("/api/admin/plans/{$free->id}", ['is_default' => false])->assertJsonValidationErrors('is_default');
        $this->deleteJson("/api/admin/plans/{$free->id}")->assertJsonValidationErrors('plan');

        $this->postJson('/api/admin/plans', ['name' => 'Starter', 'price' => 0, 'interval' => 'month', 'is_default' => true])
            ->assertCreated();

        $this->assertSame('starter', Plan::default()->slug);
        $this->assertSame(1, Plan::where('is_default', true)->count());
    }

    public function test_plans_with_subscribers_are_deactivated_instead_of_deleted(): void
    {
        $this->admin();

        $unused = Plan::create(['slug' => 'unused', 'name' => 'Unused', 'price' => 10, 'interval' => 'month']);
        $this->deleteJson("/api/admin/plans/{$unused->id}")->assertAccepted();
        $this->assertModelMissing($unused);

        $used = Plan::create(['slug' => 'used', 'name' => 'Used', 'price' => 10, 'interval' => 'month']);
        Subscription::forceCreate([
            'user_id' => User::factory()->create()->id,
            'plan' => 'used',
            'plan_id' => $used->id,
            'amount' => 10,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->deleteJson("/api/admin/plans/{$used->id}")->assertAccepted();
        $this->assertFalse($used->fresh()->active);

        // Inactive plans are hidden from the public list.
        $this->assertNotContains('used', collect($this->getJson('/api/plans')->json('data.plans'))->pluck('slug'));
    }

    public function test_a_subscriber_gets_the_features_of_their_plan(): void
    {
        $plan = Plan::create([
            'slug' => 'listener', 'name' => 'Listener', 'price' => 500, 'interval' => 'month',
            'features' => ['downloads' => true, 'badge' => false],
        ]);

        $user = User::factory()->create();
        $this->assertFalse((bool) $user->feature(Feature::DOWNLOADS));

        $user->subscriptions()->create([
            'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 500,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        $user = $user->fresh();
        $this->assertSame('listener', $user->current_plan->slug);
        $this->assertTrue($user->feature(Feature::DOWNLOADS));

        $this->actingAsFresh($user);
        $this->getJson('/api/account')->assertOk()->assertJsonPath('data.badge', false);
    }

    public function test_creators_on_a_plan_without_selling_cannot_set_a_price(): void
    {
        $this->setFeatures(Plan::default(), ['sell_releases' => false, 'receive_tips' => false]);

        $creator = User::factory()->create();
        $genre = Genre::factory()->create();
        $release = Release::factory()->create([
            'user_id' => $creator->id,
            'genre_id' => $genre->id,
            'artist_id' => Artist::factory()->create()->id,
            'price' => 0,
        ]);
        Track::factory()->create(['release_id' => $release->id, 'genre_id' => $genre->id]);

        $this->actingAsFresh($creator);

        $this->putJson("/api/creator/releases/{$release->id}", [
            'title' => $release->title,
            'genre_id' => (string) $release->genre_id,
            'artist_id' => (string) $release->artist_id,
            'price' => 1000,
        ])->assertUnprocessable()->assertJsonValidationErrors('price');

        // Fans can't tip this creator either.
        $this->actingAsFresh(User::factory()->create());
        $this->postJson('/api/payments', ['purpose' => 'tip', 'id' => $creator->username, 'amount' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id');
    }
}
