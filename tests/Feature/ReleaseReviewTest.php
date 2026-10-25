<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Configuration;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReleaseReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $admin;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-content'));

        $this->project = Project::factory()->create(['user_id' => $this->owner->id]);
        $this->track = ProjectTrack::factory()->create([
            'project_id' => $this->project->id,
            'genre_id' => Genre::factory()->create()->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::forget('configuration::build');

        parent::tearDown();
    }

    protected function reviews(bool $on): void
    {
        Configuration::where('key', 'review_releases')->update(['value' => $on ? '1' : '0']);
        Cache::forget('configuration::build');
    }

    protected function publish(array $extra = []): int
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/creator/releases', [
            'title' => $this->project->title,
            'genre_id' => (string) $this->track->genre_id,
            'artist_id' => (string) $this->track->artist_id,
            'project_id' => $this->project->id,
            'draft' => false,
            'tracks' => [[
                'title' => $this->track->title,
                'genre_id' => (string) $this->track->genre_id,
                'artist_id' => (string) $this->track->artist_id,
                'project_track_id' => $this->track->id,
            ]],
            ...$extra,
        ])->assertCreated()->json('data.id');
    }

    protected function liveIds()
    {
        return collect($this->getJson('/api/releases?limit=100')->json('data'))->pluck('id');
    }

    public function test_releases_go_live_straight_away_when_reviews_are_off(): void
    {
        $this->reviews(false);

        $id = $this->publish();

        $this->assertNull(Release::find($id)->review_status);
        $this->assertTrue($this->liveIds()->contains($id));
    }

    public function test_reviewed_releases_wait_for_approval_then_retire_older_versions(): void
    {
        $this->reviews(false);
        $first = $this->publish();

        $this->reviews(true);
        $second = $this->publish(['retire' => [$first]]);

        $this->assertSame(Release::REVIEW_PENDING, Release::find($second)->review_status);
        $this->assertFalse($this->liveIds()->contains($second));
        $this->assertNull(Release::find($first)->retired_at);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/moderation/releases')
            ->assertOk()
            ->assertJsonFragment(['id' => $second]);

        $title = Release::find($second)->title;
        $this->getJson('/api/admin/moderation/releases?search='.urlencode(strtoupper($title)))->assertJsonFragment(['id' => $second]);
        $this->getJson('/api/admin/moderation/releases?search=nothing-like-this')->assertJsonCount(0, 'data');

        $this->postJson("/api/admin/moderation/releases/{$second}/approve")->assertStatus(202);

        $this->assertSame(Release::REVIEW_APPROVED, Release::find($second)->review_status);
        $this->assertNotNull(Release::find($first)->retired_at);
        $this->assertTrue($this->liveIds()->contains($second));

        Notification::assertSentTo($this->owner, ModerationNotice::class);
    }

    public function test_rejected_releases_go_back_to_draft_with_the_note(): void
    {
        $this->reviews(true);
        $id = $this->publish();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/moderation/releases/{$id}/reject")->assertUnprocessable();
        $this->postJson("/api/admin/moderation/releases/{$id}/reject", ['note' => 'Artwork is missing.'])->assertStatus(202);

        $release = Release::find($id);
        $this->assertTrue($release->draft);
        $this->assertSame(Release::REVIEW_REJECTED, $release->review_status);

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/releases/{$id}")->assertJsonPath('data.reviewNote', 'Artwork is missing.');
    }

    public function test_only_moderators_review_releases(): void
    {
        $this->reviews(true);
        $id = $this->publish();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/moderation/releases')->assertForbidden();
        $this->postJson("/api/admin/moderation/releases/{$id}/approve")->assertForbidden();
    }
}
