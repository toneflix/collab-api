<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Takedown;
use App\Models\Track;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $reporter;

    protected User $moderator;

    protected Release $release;

    protected Track $track;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->owner = User::factory()->create();
        $this->reporter = User::factory()->create();
        $this->moderator = User::factory()->create();
        $this->moderator->givePermissionTo(Permission::findOrCreate('manage-content'));

        $genre = Genre::factory()->create();
        $this->release = Release::factory()
            ->has(Track::factory()->count(2)->state(['genre_id' => $genre->id, 'audio' => null]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => $this->owner->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
        $this->track = $this->release->tracks()->first();
    }

    protected function report(string $type, int $id, array $data = [])
    {
        Sanctum::actingAs($this->reporter);

        return $this->postJson('/api/safety/reports', [
            'type' => $type,
            'id' => $id,
            'reason' => 'spam',
            ...$data,
        ]);
    }

    protected function copyrightClaim(): array
    {
        return [
            'reason' => 'copyright',
            'details' => 'This is my song, released last year on my own label.',
            'claim' => [
                'original_url' => 'https://example.com/my-song',
                'claimant' => 'Ada Obi',
                'email' => 'ada@example.com',
                'statement' => true,
            ],
        ];
    }

    public function test_people_can_report_a_release_with_a_copyright_claim(): void
    {
        $this->report('release', $this->release->id, $this->copyrightClaim())
            ->assertCreated()
            ->assertJsonPath('data.reason', 'copyright')
            ->assertJsonPath('data.claim.claimant', 'Ada Obi');

        $this->assertDatabaseHas('safeties', [
            'model_type' => Release::class,
            'model_id' => $this->release->id,
            'user_id' => $this->reporter->id,
            'status' => Safety::PENDING,
        ]);
    }

    public function test_copyright_claims_need_details_and_the_statement(): void
    {
        $this->report('track', $this->track->id, ['reason' => 'copyright'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details', 'claim.claimant', 'claim.email', 'claim.statement']);
    }

    public function test_reporting_again_updates_the_pending_report(): void
    {
        $this->report('track', $this->track->id)->assertCreated();
        $this->report('track', $this->track->id, ['reason' => 'hate'])->assertCreated();

        $this->assertSame(1, Safety::query()->forItem($this->track)->count());
        $this->assertSame('hate', Safety::query()->forItem($this->track)->value('reason'));
    }

    public function test_owners_cannot_report_their_own_content(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/safety/reports', ['type' => 'release', 'id' => $this->release->id, 'reason' => 'spam'])
            ->assertUnprocessable();
    }

    public function test_only_moderators_can_see_the_queue(): void
    {
        Sanctum::actingAs($this->reporter);
        $this->getJson('/api/admin/moderation/queue')->assertForbidden();
    }

    public function test_the_queue_groups_reports_by_content(): void
    {
        $this->report('release', $this->release->id, $this->copyrightClaim())->assertCreated();

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson('/api/safety/reports', ['type' => 'release', 'id' => $this->release->id, 'reason' => 'spam'])->assertCreated();

        Sanctum::actingAs($this->moderator);
        $this->getJson('/api/admin/moderation/queue')
            ->assertOk()
            ->assertJsonPath('stats.pending', 1)
            ->assertJsonPath('data.0.item.type', 'release')
            ->assertJsonPath('data.0.item.owner.id', $this->owner->id)
            ->assertJsonPath('data.0.reportsCount', 2)
            ->assertJsonPath('data.0.reasons.copyright', 1)
            ->assertJsonPath('data.0.reasons.spam', 1)
            ->assertJsonCount(2, 'data.0.reports');
    }

    public function test_moderators_can_search_the_queue_and_takedowns(): void
    {
        $this->release->forceFill(['title' => 'Midnight Harbour'])->saveQuietly();
        $user = User::factory()->create(['username' => 'loudneighbour']);

        $this->report('release', $this->release->id)->assertCreated();
        $this->report('user', $user->id)->assertCreated();

        Sanctum::actingAs($this->moderator);
        $this->getJson('/api/admin/moderation/queue?search=harb')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item.id', $this->release->id);

        $this->getJson('/api/admin/moderation/queue?search=LOUDNEIGH')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item.type', 'user');

        $this->postJson("/api/admin/moderation/items/release/{$this->release->id}/takedown", ['reason' => 'spam'])->assertCreated();

        $this->getJson('/api/admin/moderation/takedowns?search=midnight')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/moderation/takedowns?search=nothing-like-this')->assertJsonCount(0, 'data');
    }

    public function test_taking_down_a_release_hides_it_and_its_tracks_from_everyone_but_the_owner(): void
    {
        $this->report('release', $this->release->id, $this->copyrightClaim())->assertCreated();

        Sanctum::actingAs($this->moderator);
        $this->postJson("/api/admin/moderation/items/release/{$this->release->id}/takedown", [
            'reason' => 'copyright',
            'note' => 'The claimant showed they own the master.',
        ])->assertCreated()->assertJsonPath('data.status', Takedown::ACTIVE);

        $this->assertNotNull($this->release->fresh()->taken_down_at);
        $this->assertSame(0, Track::query()->withoutGlobalScopes()->where('release_id', $this->release->id)->whereNull('taken_down_at')->count());
        $this->assertSame(Safety::ACTIONED, Safety::query()->forItem($this->release)->value('status'));

        Notification::assertSentTo($this->owner, ModerationNotice::class);
        Notification::assertSentTo($this->reporter, ModerationNotice::class);

        $this->getJson("/api/releases/{$this->release->id}")->assertNotFound();
        $this->getJson("/api/tracks/{$this->track->id}")->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/releases/{$this->release->id}")->assertOk()->assertJsonPath('data.takenDown', true);
    }

    public function test_owners_can_appeal_once_and_moderators_can_restore(): void
    {
        Sanctum::actingAs($this->moderator);
        $takedown = $this->postJson("/api/admin/moderation/items/release/{$this->release->id}/takedown", ['reason' => 'copyright'])
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/account/takedowns/{$takedown}/appeal", ['message' => 'I own every right to this music, here is my licence.'])
            ->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/account/takedowns')->assertOk()->assertJsonPath('data.0.canAppeal', true);
        $this->postJson("/api/account/takedowns/{$takedown}/appeal", ['message' => 'I own every right to this music, here is my licence.'])
            ->assertAccepted()
            ->assertJsonPath('data.status', Takedown::APPEALED);
        $this->postJson("/api/account/takedowns/{$takedown}/appeal", ['message' => 'Again, I own every right to this music.'])
            ->assertUnprocessable();

        Sanctum::actingAs($this->moderator);
        $this->getJson('/api/admin/moderation/takedowns?status=appealed')->assertOk()->assertJsonPath('stats.appeals', 1);
        $this->postJson("/api/admin/moderation/takedowns/{$takedown}/restore", ['resolution' => 'The licence checks out.'])
            ->assertAccepted()
            ->assertJsonPath('data.status', Takedown::RESTORED);

        $this->assertNull($this->release->fresh()->taken_down_at);
        $this->assertSame(0, Track::query()->withoutGlobalScopes()->where('release_id', $this->release->id)->whereNotNull('taken_down_at')->count());

        Sanctum::actingAs($this->reporter);
        $this->getJson("/api/releases/{$this->release->id}")->assertOk();
    }

    public function test_a_track_taken_down_on_its_own_stays_down_when_its_release_comes_back(): void
    {
        Sanctum::actingAs($this->moderator);
        $this->postJson("/api/admin/moderation/items/track/{$this->track->id}/takedown", ['reason' => 'copyright'])->assertCreated();
        $release = $this->postJson("/api/admin/moderation/items/release/{$this->release->id}/takedown", ['reason' => 'spam'])->json('data.id');

        $this->postJson("/api/admin/moderation/takedowns/{$release}/restore")->assertAccepted();

        $this->assertNotNull(Track::query()->withoutGlobalScopes()->find($this->track->id)->taken_down_at);
        $this->assertSame(1, Track::query()->withoutGlobalScopes()->where('release_id', $this->release->id)->whereNull('taken_down_at')->count());
    }

    public function test_upholding_an_appeal_keeps_the_content_down(): void
    {
        Sanctum::actingAs($this->moderator);
        $takedown = $this->postJson("/api/admin/moderation/items/track/{$this->track->id}/takedown", ['reason' => 'dmca'])->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/account/takedowns/{$takedown}/appeal", ['message' => 'This is a cover I recorded myself, with a licence.'])->assertAccepted();

        Sanctum::actingAs($this->moderator);
        $this->postJson("/api/admin/moderation/takedowns/{$takedown}/uphold", ['resolution' => 'Covers still need a mechanical licence.'])
            ->assertAccepted()
            ->assertJsonPath('data.status', Takedown::ACTIVE)
            ->assertJsonPath('data.canAppeal', false);

        $this->assertNotNull(Track::query()->withoutGlobalScopes()->find($this->track->id)->taken_down_at);
    }

    public function test_dismissing_closes_the_reports_and_tells_reporters(): void
    {
        $this->report('track', $this->track->id)->assertCreated();

        Sanctum::actingAs($this->moderator);
        $this->postJson("/api/admin/moderation/items/track/{$this->track->id}/dismiss")
            ->assertAccepted()
            ->assertJsonPath('data.closed', 1);

        $this->assertSame(Safety::DISMISSED, Safety::query()->forItem($this->track)->value('status'));
        Notification::assertSentTo($this->reporter, ModerationNotice::class);

        $this->report('track', $this->track->id)->assertUnprocessable();
    }

    public function test_accounts_can_be_reported_but_not_taken_down(): void
    {
        $this->report('user', $this->owner->id)->assertCreated();

        Sanctum::actingAs($this->moderator);
        $this->postJson("/api/admin/moderation/items/user/{$this->owner->id}/takedown", ['reason' => 'spam'])
            ->assertUnprocessable();
        $this->postJson("/api/admin/moderation/items/user/{$this->owner->id}/resolve")
            ->assertAccepted()
            ->assertJsonPath('data.closed', 1);
    }
}
