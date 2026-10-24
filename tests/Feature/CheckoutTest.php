<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Configuration;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\Release;
use App\Models\Subscription;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\Checkout;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FakeGateway implements PaymentGateway
{
    /** @var array<string, array{paid: bool, amount: float}> */
    public array $results = [];

    public array $initialized = [];

    public function initialize(string $email, float $amount, string $reference, array $metadata = []): array
    {
        $this->initialized[$reference] = $amount;

        return [
            'reference' => $reference,
            'access_code' => 'code_'.$reference,
            'authorization_url' => 'https://checkout.test/'.$reference,
        ];
    }

    public function verify(string $reference): array
    {
        $result = $this->results[$reference] ?? ['paid' => true, 'amount' => $this->initialized[$reference] ?? 0];

        return ['final' => true, ...$result, 'currency' => 'NGN', 'raw' => ['reference' => $reference]];
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        return $signature === hash_hmac('sha512', $payload, 'test-secret');
    }
}

class CheckoutTest extends TestCase
{
    use DatabaseTransactions;

    protected FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->setting('premium_monthly_price', 2000);
        $this->setting('premium_yearly_price', 20000);
        $this->setting('creator_share', 80);
        $this->setting('min_tip_amount', 100);
    }

    protected function setting(string $key, mixed $value): void
    {
        Configuration::where('key', $key)->update(['value' => $value]);
        Cache::forget('configuration::build');
    }

    protected function paidRelease(float $price = 1500, ?User $owner = null): Release
    {
        $genre = Genre::factory()->create();

        return Release::factory()
            ->has(Track::factory()->count(2)->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => false,
                'price' => $price,
                'genre_id' => $genre->id,
                'user_id' => ($owner ?? User::factory()->create())->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
    }

    /**
     * Switch user between requests. Controllers keep the user they were built with,
     * so drop them, and reload the user so cached flags like is_premium are fresh.
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

    /**
     * Start a payment and confirm it, the way the checkout widget does.
     */
    protected function pay(User $buyer, array $body): Transaction
    {
        Sanctum::actingAs($buyer);

        $reference = $this->postJson('/api/payments', $body)
            ->assertCreated()
            ->assertJsonPath('data.access_code', fn ($code) => str_starts_with($code, 'code_'))
            ->json('data.reference');

        $this->postJson("/api/payments/{$reference}/verify")->assertOk();

        return Transaction::where('reference', $reference)->firstOrFail();
    }

    public function test_a_paid_release_is_locked_until_bought(): void
    {
        $release = $this->paidRelease(1500);
        $track = $release->tracks->first();
        $buyer = User::factory()->create();

        Sanctum::actingAs($buyer);

        $this->getJson("/api/releases/{$release->slug}/tracks/{$track->id}?with=streamUrl")
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.price', 1500)
            ->assertJsonMissingPath('data.streamUrl');

        $this->getJson("/api/tracks/{$track->id}/download")->assertStatus(402);

        $transaction = $this->pay($buyer, ['purpose' => 'release', 'id' => $release->id]);

        $this->assertSame('complete', $transaction->status);
        $this->assertSame($release->user_id, $transaction->recipient_id);
        $this->assertEquals(1200, $transaction->recipient_share);

        $this->getJson("/api/releases/{$release->slug}/tracks/{$track->id}?with=streamUrl")
            ->assertJsonPath('data.locked', false)
            ->assertJsonStructure(['data' => ['streamUrl']]);

        $this->getJson("/api/releases/{$release->slug}")
            ->assertJsonPath('data.purchased', true);

        // Buying it twice is refused.
        $this->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->id])
            ->assertUnprocessable();
    }

    public function test_free_and_draft_releases_cannot_be_bought(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $free = $this->paidRelease(0);
        $this->postJson('/api/payments', ['purpose' => 'release', 'id' => $free->id])->assertUnprocessable();

        $draft = $this->paidRelease(500);
        $draft->update(['draft' => true]);
        $this->postJson('/api/payments', ['purpose' => 'release', 'id' => $draft->id])->assertNotFound();
    }

    public function test_paying_less_than_the_price_does_not_unlock(): void
    {
        $release = $this->paidRelease(1500);
        $buyer = User::factory()->create();
        Sanctum::actingAs($buyer);

        $reference = $this->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->id])->json('data.reference');
        $this->gateway->results[$reference] = ['paid' => true, 'amount' => 10];

        $this->postJson("/api/payments/{$reference}/verify")->assertStatus(400);

        $this->assertSame('rejected', Transaction::where('reference', $reference)->value('status'));
        $this->assertFalse($release->fresh()->isUnlockedFor($buyer));
    }

    public function test_a_payment_still_in_progress_stays_pending(): void
    {
        $release = $this->paidRelease(1500);
        Sanctum::actingAs(User::factory()->create());

        $reference = $this->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->id])->json('data.reference');
        $this->gateway->results[$reference] = ['paid' => false, 'final' => false, 'amount' => 0];

        $this->postJson("/api/payments/{$reference}/verify")->assertStatus(400);

        $this->assertSame('pending', Transaction::where('reference', $reference)->value('status'));
    }

    public function test_the_playlist_skips_tracks_the_listener_has_not_bought(): void
    {
        $paid = $this->paidRelease(1500);
        $free = $this->paidRelease(0);

        Sanctum::actingAs(User::factory()->create());

        $ids = collect($this->getJson('/api/playlist?limit=500')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->intersect($free->tracks->pluck('id'))->isNotEmpty());
        $this->assertTrue($ids->intersect($paid->tracks->pluck('id'))->isEmpty());
    }

    public function test_stems_can_be_bought_and_downloaded(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $projectTrack = ProjectTrack::factory()->create(['project_id' => $project->id]);
        ProjectTrackStem::factory()->create(['track_id' => $projectTrack->id, 'user_id' => $owner->id, 'src' => 'drums.mp3']);

        $release = $this->paidRelease(0, $owner);
        $track = $release->tracks->first();
        $track->update(['stems_price' => 5000, 'project_track_id' => $projectTrack->id]);

        $buyer = User::factory()->create();
        Sanctum::actingAs($buyer);

        $this->getJson("/api/tracks/{$track->id}/stems")->assertStatus(402);

        $transaction = $this->pay($buyer, ['purpose' => 'stems', 'id' => $track->id]);
        $this->assertEquals(5000, $transaction->amount);
        $this->assertSame($owner->id, $transaction->recipient_id);

        $this->getJson("/api/tracks/{$track->id}/stems")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_stems_without_a_price_are_not_for_sale(): void
    {
        $release = $this->paidRelease(0);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/payments', ['purpose' => 'stems', 'id' => $release->tracks->first()->id])
            ->assertUnprocessable();
    }

    public function test_listeners_can_tip_creators(): void
    {
        $creator = User::factory()->create();
        $fan = User::factory()->create();

        Sanctum::actingAs($fan);

        $this->postJson('/api/payments', ['purpose' => 'tip', 'id' => $creator->username, 'amount' => 50])
            ->assertUnprocessable();

        $transaction = $this->pay($fan, [
            'purpose' => 'tip',
            'id' => $creator->username,
            'amount' => 1000,
            'message' => 'Love this',
        ]);

        $this->assertSame('Love this', $transaction->meta['message']);
        $this->assertEquals(800, $transaction->recipient_share);

        $this->actingAsFresh($creator);

        $this->getJson('/api/account/earnings')
            ->assertOk()
            ->assertJsonPath('summary.earned', 800)
            ->assertJsonPath('data.0.purpose', 'tip')
            ->assertJsonPath('data.0.message', 'Love this');

        // You can't tip yourself.
        $this->postJson('/api/payments', ['purpose' => 'tip', 'id' => $creator->username, 'amount' => 1000])
            ->assertUnprocessable();
    }

    public function test_premium_adds_time_and_unlocks_downloads(): void
    {
        $listener = User::factory()->create();
        $track = $this->paidRelease(0)->tracks->first();
        $track->update(['audio' => 'song.mp3']);

        Sanctum::actingAs($listener);

        $this->getJson("/api/tracks/{$track->id}/download")->assertForbidden();

        $this->pay($listener, ['purpose' => 'premium', 'plan' => 'monthly']);
        $this->pay($listener, ['purpose' => 'premium', 'plan' => 'yearly']);

        $listener = $this->actingAsFresh($listener);
        $this->assertTrue($listener->is_premium);

        // The yearly plan starts when the month runs out.
        $this->assertTrue(
            $listener->subscriptions()->first()->ends_at->isSameDay(now()->addMonth()->addYear())
        );

        $this->getJson('/api/account/subscription')
            ->assertOk()
            ->assertJsonPath('data.premium', true)
            ->assertJsonPath('data.plans.monthly', 2000);

        $this->getJson("/api/tracks/{$track->id}/download")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'filename']]);
    }

    public function test_premium_needs_a_valid_plan(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/payments', ['purpose' => 'premium', 'plan' => 'weekly'])->assertUnprocessable();

        $this->setting('premium_monthly_price', 0);
        $this->postJson('/api/payments', ['purpose' => 'premium', 'plan' => 'monthly'])->assertUnprocessable();
    }

    public function test_settling_twice_only_counts_once(): void
    {
        $listener = User::factory()->create();
        $transaction = $this->pay($listener, ['purpose' => 'premium', 'plan' => 'monthly']);

        app(Checkout::class)->settle($transaction, true, 2000);
        $this->postJson("/api/payments/{$transaction->reference}/verify")->assertOk();

        $this->assertSame(1, Subscription::where('user_id', $listener->id)->count());
    }

    public function test_the_webhook_checks_the_signature_and_confirms_the_payment(): void
    {
        $listener = User::factory()->create();
        Sanctum::actingAs($listener);

        $reference = $this->postJson('/api/payments', ['purpose' => 'premium', 'plan' => 'monthly'])->json('data.reference');

        $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);

        $this->call('POST', '/api/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'wrong',
        ], $payload)->assertUnauthorized();

        $this->assertSame('pending', Transaction::where('reference', $reference)->value('status'));

        $this->call('POST', '/api/payments/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'test-secret'),
        ], $payload)->assertOk();

        $this->assertSame('complete', Transaction::where('reference', $reference)->value('status'));
        $this->assertTrue($listener->fresh()->is_premium);
    }

    public function test_purchases_are_listed_for_the_buyer(): void
    {
        $release = $this->paidRelease(700);
        $buyer = User::factory()->create();

        $this->pay($buyer, ['purpose' => 'release', 'id' => $release->id]);

        $this->getJson('/api/account/purchases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.purpose', 'release')
            ->assertJsonPath('data.0.item.title', $release->title)
            ->assertJsonMissingPath('data.0.earned');
    }

    public function test_free_accounts_hit_the_project_limit_and_premium_lifts_it(): void
    {
        $this->setting('free_max_projects', 1);
        $this->setting('premium_max_projects', 0);

        $creator = User::factory()->create();
        Sanctum::actingAs($creator);

        $this->postJson('/api/creator/projects', ['title' => 'First'])->assertCreated();
        $this->postJson('/api/creator/projects', ['title' => 'Second'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $creator->subscriptions()->create([
            'plan' => 'monthly',
            'amount' => 2000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->actingAsFresh($creator);

        $this->postJson('/api/creator/projects', ['title' => 'Second'])->assertCreated();
    }

    public function test_free_projects_have_a_collaborator_limit(): void
    {
        $this->setting('free_max_collaborators', 1);

        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->collaborators()->delete(); // the factory adds random ones

        Sanctum::actingAs($owner);

        [$first, $second] = User::factory()->count(2)->create();

        $this->postJson("/api/creator/projects/{$project->id}/collaborators", ['user_id' => (string) $first->id])
            ->assertCreated();

        $this->postJson("/api/creator/projects/{$project->id}/collaborators", ['user_id' => (string) $second->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }
}
