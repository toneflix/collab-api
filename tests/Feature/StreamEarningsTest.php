<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\TrackOrigin;
use App\Models\Artist;
use App\Models\Configuration;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\StreamPeriod;
use App\Models\Track;
use App\Models\TrackPlay;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Streams\StreamEarnings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StreamEarningsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $creator;

    protected User $listener;

    protected Release $release;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Notification::fake();
        $this->setting('stream_daily_cap', 200);

        $this->creator = $this->premium();
        $this->listener = User::factory()
            ->create();

        $artist = Artist::factory()
            ->create(['user_id' => $this->creator->id]);

        $this->release = Release::factory()
            ->create(['user_id' => $this->creator->id, 'artist_id' => $artist->id]);
    }

    protected function tearDown(): void
    {
        Cache::forget('configuration::build');

        parent::tearDown();
    }

    protected function setting(string $key, mixed $value): void
    {
        Configuration::query()
            ->where('key', $key)
            ->update(['value' => $value]);
        Cache::forget('configuration::build');
    }

    protected function premium(bool $earns = true): User
    {
        $plan = Plan::create([
            'slug' => 'streams-' . uniqid(), 'name' => 'Streams', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::EARN_STREAMS->value => $earns],
        ]);

        $user = User::factory()
            ->create();
        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
                'starts_at' => now()->subYear(), 'ends_at' => now()->addYear(),
            ]);

        return $user->fresh();
    }

    protected function track(?TrackOrigin $origin = null, ?Release $release = null): Track
    {
        $track = Track::factory()
            ->create(['release_id' => ($release ?? $this->release)->id, 'audio' => null]);
        $track
            ->forceFill(['origin' => $origin?->value, 'origin_by' => 'admin'])
            ->saveQuietly();
        $track
            ->plays()
            ->delete();

        return $track;
    }

    protected function play(Track $track, User $listener, int $seconds = 45)
    {
        Sanctum::actingAs($listener);

        return $this->postJson("/api/tracks/{$track->id}/plays", ['seconds' => $seconds]);
    }

    protected function paid(Track $track): int
    {
        return TrackPlay::query()
            ->where('track_id', $track->id)
            ->where('paid', true)
            ->count();
    }

    /**
     * A completed Premium payment and the period it bought.
     */
    protected function payment(float $amount, Carbon $starts, Carbon $ends): Transaction
    {
        $plan = Plan::query()
            ->where('slug', 'premium')
            ->firstOrFail();

        $payment = Transaction::query()->forceCreate([
            'reference' => 'prem_' . Str::random(10), 'purpose' => 'premium', 'status' => 'complete',
            'amount' => $amount, 'user_id' => $this->listener->id, 'paid_at' => $starts,
            'transactable_type' => $plan->getMorphClass(), 'transactable_id' => $plan->id,
        ]);

        $this->listener
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => $amount,
                'starts_at' => $starts, 'ends_at' => $ends, 'transaction_id' => $payment->id,
            ]);

        return $payment;
    }

    public function test_payments_for_several_months_are_spread_over_them(): void
    {
        $month = now()
            ->subMonthsNoOverflow(2)
            ->startOfMonth();

        $this->payment(36500, $month->copy(), $month->copy()->addDays(365));
        $this->payment(500, $month->copy()->addMonthNoOverflow(), $month->copy()->addMonthsNoOverflow(2));

        $earnings = app(StreamEarnings::class);
        $first = $month->daysInMonth * 100;
        $second = $month->copy()->addMonthNoOverflow()->daysInMonth * 100 + 500;

        $this->assertEqualsWithDelta($first, $earnings->revenue($month->copy(), $month->copy()->endOfMonth()), 0.01);
        $this->assertEqualsWithDelta(
            $second,
            $earnings->revenue($month->copy()->addMonthNoOverflow(), $month->copy()->addMonthNoOverflow()->endOfMonth()),
            0.01,
        );
    }

    public function test_signed_in_plays_long_enough_are_paid_once_a_day(): void
    {
        $track = $this->track(TrackOrigin::RECORDED);

        $this->play($track, $this->listener, 10)->assertOk();
        $this->assertSame(0, $this->paid($track));

        $this->play($track, $this->listener, 40)->assertOk();
        $this->play($track, $this->listener, 200)->assertOk();

        $this->assertSame(1, $this->paid($track));
        $this->assertSame(200, TrackPlay::query()->where('track_id', $track->id)->sole()->seconds);
        $this->assertSame(1, Track::query()->find($track->id)->streams);

        $this->travel(1)->day();
        $this->play($track, $this->listener)->assertOk();
        $this->assertSame(2, $this->paid($track));

        auth()->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->postJson("/api/tracks/{$track->id}/plays", ['seconds' => 60])->assertUnauthorized();
    }

    public function test_owners_collaborators_and_free_creators_get_no_paid_plays(): void
    {
        $track = $this->track(TrackOrigin::COLLABORATION);

        $this->play($track, $this->creator)->assertOk();
        $this->assertSame(0, $this->paid($track));

        $project = Project::factory()
            ->create(['user_id' => $this->creator->id]);
        $collaborator = User::factory()
            ->create();
        $project
            ->collaborators()
            ->create(['user_id' => $collaborator->id, 'role' => 'Mixer']);
        $studio = $project
            ->tracks()
            ->create(['title' => 'Studio cut', 'genre_id' => $track->genre_id, 'artist_id' => $this->release->artist_id]);
        $track
            ->forceFill(['project_track_id' => $studio->id])
            ->saveQuietly();

        $this->play($track, $collaborator)->assertOk();
        $this->assertSame(0, $this->paid($track));

        $free = User::factory()
            ->create();
        $freeRelease = Release::factory()
            ->create(['user_id' => $free->id, 'artist_id' => $this->release->artist_id]);
        $freeTrack = $this->track(TrackOrigin::RECORDED, $freeRelease);

        $this->play($freeTrack, $this->listener)->assertOk();
        $this->assertSame(0, $this->paid($freeTrack));
        $this->assertSame(1, Track::query()->find($freeTrack->id)->streams);
    }

    public function test_listeners_have_a_daily_cap(): void
    {
        $this->setting('stream_daily_cap', 2);

        $tracks = collect(range(1, 3))->map(fn() => $this->track(TrackOrigin::RECORDED));
        $tracks->each(fn(Track $track) => $this->play($track, $this->listener)->assertOk());

        $this->assertSame(2, TrackPlay::query()->where('user_id', $this->listener->id)->where('paid', true)->count());
    }

    public function test_months_are_settled_by_weighted_plays(): void
    {
        $this->setting('stream_pool_share', 50);

        $collab = $this->track(TrackOrigin::COLLABORATION);
        $agentic = $this->track(TrackOrigin::AGENTIC);
        $other = $this->premium();
        $otherRelease = Release::factory()
            ->create(['user_id' => $other->id, 'artist_id' => $this->release->artist_id]);
        $recorded = $this->track(TrackOrigin::RECORDED, $otherRelease);
        $unranked = $this->track(null, $otherRelease);

        $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(3));

        $this->payment(3500, now()->startOfMonth(), now()->startOfMonth()->addMonthNoOverflow());

        foreach ([$collab, $agentic, $recorded, $unranked] as $track) {
            $this->play($track, $this->listener)->assertOk();
        }
        $this->play($collab, User::factory()->create())->assertOk();

        $this->travelBack();

        // Weighted plays: 2 x 1.5 + 0.5 = 3.5 for the creator, 1 + 0.5 = 1.5 for the other.
        $period = app(StreamEarnings::class)->settle(now()->subMonthNoOverflow());

        $this->assertSame(StreamPeriod::SETTLED, $period->status);
        $this->assertEqualsWithDelta(1750, $period->pool, 0.01);
        $this->assertEqualsWithDelta(5.0, $period->weighted, 0.01);
        $this->assertEqualsWithDelta(350, $period->rate, 0.01);
        $this->assertSame(2, $period->earners);

        $this->assertEqualsWithDelta(1225, (float) $this->creator->earnings()->where('purpose', 'streams')->sum('recipient_share'), 0.01);
        $this->assertEqualsWithDelta(525, (float) $other->earnings()->where('purpose', 'streams')->sum('recipient_share'), 0.01);

        app(StreamEarnings::class)->settle(now()->subMonthNoOverflow());
        $this->assertSame(2, Transaction::query()->where('purpose', 'streams')->whereMorphedTo('transactable', $period)->count());

        Sanctum::actingAs($this->creator);
        $this->getJson('/api/account/earnings/streams')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.earned', 1225);
    }

    public function test_admins_see_months_and_settle_them(): void
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo(Permission::findOrCreate('manage-payouts'));
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/payouts/streams')
            ->assertOk()
            ->assertJsonPath('current.month', now()->format('Y-m'))
            ->assertJsonPath('rules.weights.collaboration', 1.5);

        $this->postJson('/api/admin/payouts/streams/settle', ['month' => now()->format('Y-m')])
            ->assertUnprocessable();

        $last = now()
            ->subMonthNoOverflow()
            ->format('Y-m');

        $this->postJson('/api/admin/payouts/streams/settle', ['month' => $last])
            ->assertAccepted()
            ->assertJsonPath('data.month', $last)
            ->assertJsonPath('data.status', StreamPeriod::SETTLED);

        $this->artisan('earnings:settle-streams')->assertSuccessful();
        $this->assertSame(1, StreamPeriod::query()->count());
    }
}
