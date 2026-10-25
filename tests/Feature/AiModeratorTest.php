<?php

namespace Tests\Feature;

use App\Models\AiReview;
use App\Models\Artist;
use App\Models\ArtistClaim;
use App\Models\Genre;
use App\Models\Release;
use App\Models\User;
use App\Notifications\ModerationNotice;
use App\Services\Ai\AiSettings;
use App\Services\Moderation\Ai\ModeratorModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiModeratorTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $claimant;

    protected Artist $artist;

    /** @var list<array{system: string, prompt: string}> */
    protected array $asked = [];

    /** @var list<string> */
    protected array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-content'));
        $this->claimant = User::factory()->create();
        $this->artist = Artist::factory()->create(['user_id' => null, 'verified' => false]);

        $test = $this;
        $this->app->instance(ModeratorModel::class, new class($test) implements ModeratorModel
        {
            public function __construct(protected AiModeratorTest $test) {}

            public function ask(string $system, string $prompt, ?string $image = null): string
            {
                return $this->test->answer($system, $prompt);
            }

            public function name(): string
            {
                return 'fake/moderator';
            }

            public function available(): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        AiSettings::flush();
        Cache::forget('configuration::build');

        parent::tearDown();
    }

    public function answer(string $system, string $prompt): string
    {
        $this->asked[] = compact('system', 'prompt');

        return array_shift($this->answers) ?? '';
    }

    protected function verdict(string $decision, int $confidence, string $note = 'Thanks for your patience.'): string
    {
        return json_encode(['decision' => $decision, 'confidence' => $confidence, 'summary' => "Looks like {$decision}.", 'note' => $note]);
    }

    protected function claim(): ArtistClaim
    {
        Sanctum::actingAs($this->claimant);

        $this->postJson("/api/artists/{$this->artist->username}/claims", [
            'role' => 'manager',
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'links' => ['https://instagram.com/artist'],
            'message' => 'I have managed this artist since 2021 and handle all their releases.',
        ])->assertCreated();

        return ArtistClaim::query()->where('artist_id', $this->artist->id)->latest('id')->firstOrFail();
    }

    public function test_admin_can_send_a_claim_and_the_moderator_approves_it(): void
    {
        $claim = $this->claim();
        $this->answers = ["Here you go:\n```json\n".$this->verdict('approve', 95)."\n```"];

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/ai-review")->assertAccepted();

        $review = $claim->aiReviews()->sole();
        $this->assertSame(AiReview::DECIDED, $review->status);
        $this->assertSame(AiReview::MANUAL, $review->mode);
        $this->assertSame(95, $review->confidence);
        $this->assertSame(ArtistClaim::APPROVED, $claim->refresh()->status);
        $this->assertNull($claim->reviewed_by);
        $this->assertSame($this->claimant->id, (int) $this->artist->refresh()->user_id);
        $this->assertStringContainsString($this->artist->name, $this->asked[0]['prompt']);
        Notification::assertSentTo($this->claimant, ModerationNotice::class);

        $this->getJson('/api/admin/moderation/claims?status=approved')
            ->assertJsonPath('data.0.reviewer', 'AI moderator')
            ->assertJsonPath('data.0.aiReview.decision', 'approve');
    }

    public function test_manual_reviews_always_decide(): void
    {
        $claim = $this->claim();
        $this->answers = [$this->verdict('unsure', 20, 'We could not confirm you manage this artist.')];

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/ai-review")->assertAccepted();

        $this->assertStringContainsString('You must pick one', $this->asked[0]['system']);
        $this->assertSame(ArtistClaim::REJECTED, $claim->refresh()->status);
        $this->assertSame('We could not confirm you manage this artist.', $claim->review_note);
        $this->assertSame(AiReview::REJECT, $claim->aiReviews()->sole()->decision);
    }

    public function test_unreadable_answers_are_retried_then_fail(): void
    {
        $claim = $this->claim();
        $this->answers = ['I think it is fine', 'Still not JSON'];

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/ai-review")->assertAccepted();

        $this->assertCount(2, $this->asked);
        $this->assertSame(AiReview::FAILED, $claim->aiReviews()->sole()->status);
        $this->assertTrue($claim->refresh()->isPending());

        $this->answers = [$this->verdict('reject', 90, 'The links are unrelated.')];
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/ai-review")->assertAccepted();
        $this->assertSame(ArtistClaim::REJECTED, $claim->refresh()->status);
    }

    public function test_claims_are_sent_automatically_when_turned_on(): void
    {
        AiSettings::update(['ai_moderator_claims_auto' => true, 'ai_moderator_confidence' => 80]);
        $this->answers = [$this->verdict('approve', 90)];

        $claim = $this->claim();

        $this->assertSame(AiReview::AUTO, $claim->aiReviews()->sole()->mode);
        $this->assertSame(ArtistClaim::APPROVED, $claim->refresh()->status);
    }

    public function test_unsure_automatic_reviews_are_flagged_for_a_person(): void
    {
        AiSettings::update(['ai_moderator_claims_auto' => true, 'ai_moderator_confidence' => 80]);
        $this->answers = [$this->verdict('approve', 60)];

        $claim = $this->claim();

        $review = $claim->aiReviews()->sole();
        $this->assertSame(AiReview::FLAGGED, $review->status);
        $this->assertStringContainsString('"unsure"', $this->asked[0]['system']);
        $this->assertTrue($claim->refresh()->isPending());
        $this->assertNull($this->artist->refresh()->user_id);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/moderation/claims')
            ->assertJsonPath('data.0.aiReview.status', 'flagged')
            ->assertJsonPath('stats.aiModerator', true);
    }

    public function test_automatic_review_is_off_by_default(): void
    {
        $claim = $this->claim();

        $this->assertSame(0, $claim->aiReviews()->count());
        $this->assertSame([], $this->asked);
    }

    public function test_releases_can_be_sent_and_sent_back_with_a_note(): void
    {
        $owner = User::factory()->create();
        $release = Release::factory()->create([
            'user_id' => $owner->id,
            'artist_id' => $this->artist->id,
            'genre_id' => Genre::factory()->create()->id,
            'draft' => false,
            'review_status' => Release::REVIEW_PENDING,
        ]);
        $this->answers = [$this->verdict('reject', 85, 'Remove other artists\' names from the title.')];

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/moderation/releases/{$release->id}/ai-review")->assertAccepted();

        $release = Release::query()->withoutGlobalScopes()->find($release->id);
        $this->assertSame(Release::REVIEW_REJECTED, $release->review_status);
        $this->assertTrue($release->draft);
        $this->assertSame('Remove other artists\' names from the title.', $release->review_note);
        $this->assertStringContainsString($release->title, $this->asked[0]['prompt']);
        Notification::assertSentTo($owner, ModerationNotice::class);
    }

    public function test_only_moderators_can_send_and_only_pending_items(): void
    {
        $claim = $this->claim();

        $this->postJson("/api/admin/moderation/claims/{$claim->id}/ai-review")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/reject", ['note' => 'No.'])->assertAccepted();
        $this->postJson("/api/admin/moderation/claims/{$claim->id}/ai-review")->assertUnprocessable();
    }

    public function test_admins_can_turn_automatic_review_on(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('manage-configuration'));
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/ai', [
            'ai_moderator_releases_auto' => true,
            'ai_moderator_confidence' => 70,
        ])->assertAccepted();

        $this->assertTrue(AiSettings::autoModerates(new Release));
        $this->assertFalse(AiSettings::autoModerates(new ArtistClaim));
        $this->assertSame(70, AiSettings::moderatorConfidence());
        $this->assertSame('70', AiSettings::get('ai_moderator_confidence'));
    }
}
