<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\TrackOrigin;
use App\Models\Artist;
use App\Models\Configuration;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\StreamCheck;
use App\Models\Track;
use App\Models\TrackPlay;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Referrals\Referrals;
use App\Services\Streams\StreamChecks;
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

class StreamInsightsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $creator;

    protected Release $release;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Notification::fake();
        $this->setting('stream_daily_cap', 200);
        $this->setting('stream_check_plays', 3);
        $this->setting('stream_check_share', 80);

        $this->creator = $this->premium();

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

    protected function premium(): User
    {
        $plan = Plan::create([
            'slug' => 'streams-' . uniqid(), 'name' => 'Streams', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::EARN_STREAMS->value => true],
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

    protected function track(?Release $release = null, TrackOrigin $origin = TrackOrigin::RECORDED): Track
    {
        $track = Track::factory()
            ->create(['release_id' => ($release ?? $this->release)->id, 'audio' => null]);
        $track
            ->forceFill(['origin' => $origin->value, 'origin_by' => 'admin'])
            ->saveQuietly();
        $track
            ->plays()
            ->delete();

        return $track;
    }

    /**
     * Switch user between requests. Controllers keep the user they were built with, so drop them.
     */
    protected function actAs(User $user): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($user);
    }

    protected function play(Track $track, User $listener): void
    {
        $this->actAs($listener);

        $this
            ->postJson("/api/tracks/{$track->id}/plays", ['seconds' => 60])
            ->assertOk();
    }

    protected function admin(): User
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo(Permission::findOrCreate('manage-payouts', 'web'));

        return $admin;
    }

    protected function payment(float $amount, Carbon $starts, Carbon $ends): void
    {
        $plan = Plan::query()
            ->where('slug', 'premium')
            ->firstOrFail();
        $payer = User::factory()
            ->create();

        $payment = Transaction::query()->forceCreate([
            'reference' => 'prem_' . Str::random(10), 'purpose' => 'premium', 'status' => 'complete',
            'amount' => $amount, 'user_id' => $payer->id, 'paid_at' => $starts,
            'transactable_type' => $plan->getMorphClass(), 'transactable_id' => $plan->id,
        ]);

        $payer
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => $amount,
                'starts_at' => $starts, 'ends_at' => $ends, 'transaction_id' => $payment->id,
            ]);
    }

    public function test_listeners_looping_one_creator_are_held_until_reviewed(): void
    {
        $tracks = collect(range(1, 4))->map(fn() => $this->track());
        $looper = User::factory()
            ->create();
        $fan = User::factory()
            ->create();

        $tracks
            ->take(3)
            ->each(fn(Track $track) => $this->play($track, $looper));
        $this->play($tracks[0], $fan);

        $this->assertSame(1, app(StreamChecks::class)->scan(now()));

        $check = StreamCheck::query()->sole();

        $this->assertSame($looper->id, $check->user_id);
        $this->assertSame(3, $check->plays);
        $this->assertSame(3, TrackPlay::query()->where('user_id', $looper->id)->where('held', true)->count());

        // A new paid play from the same listener is held straight away.
        $this->play($tracks[3], $looper);
        $this->assertTrue(TrackPlay::query()->where('user_id', $looper->id)->where('track_id', $tracks[3]->id)->sole()->held);

        $this->actAs($this->creator);
        $this
            ->getJson('/api/account/earnings/streams')
            ->assertOk()
            ->assertJsonPath('data.plays', 1)
            ->assertJsonPath('data.held', 4);

        $admin = $this->admin();
        $this->actAs($admin);

        $this
            ->getJson('/api/admin/payouts/stream-checks')
            ->assertOk()
            ->assertJsonPath('summary.pendingCount', 1)
            ->assertJsonPath('data.0.listener.id', $looper->id)
            ->assertJsonPath('data.0.owner.id', $this->creator->id)
            ->assertJsonCount(4, 'data.0.tracks');

        $this
            ->putJson("/api/admin/payouts/stream-checks/{$check->id}", ['decision' => 'clear'])
            ->assertAccepted()
            ->assertJsonPath('data.status', StreamCheck::CLEARED);

        $this->assertSame(0, TrackPlay::query()->where('user_id', $looper->id)->where('held', true)->count());
        $this
            ->putJson("/api/admin/payouts/stream-checks/{$check->id}", ['decision' => 'confirm'])
            ->assertUnprocessable();

        // A cleared check is not opened again that month.
        $this->assertSame(0, app(StreamChecks::class)->scan(now()));
    }

    public function test_held_streams_skip_the_payout_and_are_paid_when_cleared(): void
    {
        $tracks = collect(range(1, 3))->map(fn() => $this->track());
        $looper = User::factory()
            ->create();
        $fan = User::factory()
            ->create();

        $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(2));

        $this->payment(1000, now()->startOfMonth(), now()->startOfMonth()->addMonthNoOverflow());
        $tracks->each(fn(Track $track) => $this->play($track, $looper));
        $this->play($tracks[0], $fan);

        $this->travelBack();

        $this->setting('stream_pool_share', 40);

        $period = app(StreamEarnings::class)->settle(now()->subMonthNoOverflow());

        // Only the fan's play counts: the pool of 400 pays 400 a weighted stream.
        $this->assertSame(1, $period->plays);
        $this->assertEqualsWithDelta(400, $period->rate, 0.01);
        $this->assertEqualsWithDelta(400, (float) $this->creator->earnings()->where('purpose', 'streams')->sum('recipient_share'), 0.01);

        $check = StreamCheck::query()->sole();

        $this->actAs($this->admin());
        $this
            ->putJson("/api/admin/payouts/stream-checks/{$check->id}", ['decision' => 'clear'])
            ->assertAccepted()
            ->assertJsonPath('data.topUp', 1200);

        $this->assertEqualsWithDelta(1600, (float) $this->creator->earnings()->where('purpose', 'streams')->sum('recipient_share'), 0.01);
    }

    public function test_creators_see_stats_for_their_tracks_albums_and_projects(): void
    {
        $project = Project::factory()
            ->create(['user_id' => $this->creator->id]);
        $this->release
            ->forceFill(['project_id' => $project->id])
            ->saveQuietly();

        $hit = $this->track(origin: TrackOrigin::COLLABORATION);
        $quiet = $this->track();
        $listeners = User::factory()
            ->count(2)
            ->create();

        $listeners->each(fn(User $listener) => $this->play($hit, $listener));
        $this->play($quiet, $this->creator);

        $this->actAs($this->creator);

        $this
            ->getJson("/api/account/stats/track/{$hit->id}")
            ->assertOk()
            ->assertJsonPath('data.item.title', $hit->title)
            ->assertJsonPath('data.totals.plays', 2)
            ->assertJsonPath('data.totals.listeners', 2)
            ->assertJsonPath('data.totals.paid', 2)
            ->assertJsonCount(12, 'data.months')
            ->assertJsonPath('data.months.11.month', now()->format('Y-m'))
            ->assertJsonPath('data.months.11.paid', 2);

        $this
            ->getJson("/api/account/stats/album/{$this->release->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.plays', 3)
            ->assertJsonPath('data.totals.paid', 2);

        $this
            ->getJson("/api/account/stats/project/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.item.type', 'project')
            ->assertJsonPath('data.totals.plays', 3);

        $this
            ->getJson("/api/account/earnings/streams/tracks?release_id={$this->release->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $hit->id)
            ->assertJsonPath('data.0.paid', 2)
            ->assertJsonPath('data.0.origin', 'collaboration')
            ->assertJsonPath('data.1.paid', 0)
            ->assertJsonPath('month.month', now()->format('Y-m'));

        $this
            ->getJson('/api/account/earnings/streams')
            ->assertOk()
            ->assertJsonCount(12, 'data.months');

        $this->actAs($listeners[0]);

        $this
            ->getJson("/api/account/stats/track/{$hit->id}")
            ->assertForbidden();
        $this
            ->getJson("/api/account/stats/album/{$this->release->id}")
            ->assertForbidden();
        $this
            ->getJson("/api/account/stats/project/{$project->id}")
            ->assertForbidden();
    }

    public function test_inviters_see_what_each_invitee_earned_them_by_month(): void
    {
        $this->setting('loyalty_rate', 10);
        $this->setting('loyalty_months', 12);

        $inviter = User::factory()
            ->create();
        $invitees = User::factory()
            ->count(2)
            ->create(['referred_by_id' => $inviter->id, 'referred_at' => now()->subMonths(2)]);
        $referrals = app(Referrals::class);

        $pay = function (User $payer, float $amount, Carbon $at) use ($referrals) {
            $payment = Transaction::query()->forceCreate([
                'reference' => 'pay_' . Str::random(10), 'purpose' => 'credits', 'status' => 'complete',
                'amount' => $amount, 'user_id' => $payer->id, 'paid_at' => $at,
                'transactable_type' => $payer->getMorphClass(), 'transactable_id' => $payer->id,
            ]);

            $referrals->reward($payment);
        };

        $pay($invitees[0], 1000, now()->subMonthNoOverflow());
        $pay($invitees[0], 2000, now());
        $pay($invitees[1], 500, now());

        $this->actAs($inviter);

        $this
            ->getJson('/api/account/referrals')
            ->assertOk()
            ->assertJsonCount(12, 'data.months')
            ->assertJsonPath('data.months.10.earned', 100)
            ->assertJsonPath('data.months.11.earned', 250)
            ->assertJsonPath('data.months.11.payments', 2);

        $this
            ->getJson('/api/account/referrals/invites?month=' . now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('month', now()->format('Y-m'))
            ->assertJsonPath('data.0.id', $invitees[0]->id)
            ->assertJsonPath('data.0.monthEarned', 200)
            ->assertJsonPath('data.0.monthPayments', 1)
            ->assertJsonPath('data.0.earned', 300)
            ->assertJsonPath('data.0.payments', 2)
            ->assertJsonPath('data.1.monthEarned', 50);

        $endsAt = $this
            ->getJson('/api/account/referrals/invites')
            ->assertOk()
            ->json('data.0.rewardsEndAt');

        $this->assertNotNull($endsAt);
    }
}
