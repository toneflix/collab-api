<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeaturedArtistsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    protected Artist $main;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->owner = User::factory()
            ->create();
        $this->main = Artist::factory()
            ->create(['user_id' => $this->owner->id, 'name' => 'Dave']);
        $this->project = Project::factory()
            ->create(['user_id' => $this->owner->id]);
        $this->track = ProjectTrack::factory()
            ->create([
                'project_id' => $this->project->id,
                'genre_id' => Genre::factory()->create()->id,
                'artist_id' => $this->main->id,
            ]);

        Sanctum::actingAs($this->owner);
    }

    /**
     * @return array<string, mixed>
     */
    protected function trackForm(array $extra = []): array
    {
        return [
            'title' => $this->track->title,
            'genre_id' => (string) $this->track->genre_id,
            'artist_id' => (string) $this->main->id,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function releaseForm(array $track = [], array $extra = []): array
    {
        return [
            'title' => $this->project->title,
            'genre_id' => (string) $this->track->genre_id,
            'artist_id' => (string) $this->main->id,
            'project_id' => $this->project->id,
            'tracks' => [[
                'title' => $this->track->title,
                'genre_id' => (string) $this->track->genre_id,
                'artist_id' => (string) $this->main->id,
                'project_track_id' => $this->track->id,
                ...$track,
            ]],
            ...$extra,
        ];
    }

    /**
     * @return list<int>
     */
    protected function featuredOn(Track $track): array
    {
        return $track
            ->featured()
            ->pluck('artists.id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    public function test_studio_tracks_feature_artists_in_order(): void
    {
        $daniel = Artist::factory()
            ->create(['name' => 'Daniel']);
        $ada = Artist::factory()
            ->create(['name' => 'Ada']);

        $this
            ->putJson(
                "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}?with=artist",
                $this->trackForm(['featured_ids' => "{$ada->id},{$daniel->id},{$this->main->id}"]),
            )
            ->assertStatus(202)
            ->assertJsonPath('data.featured.0.name', 'Ada')
            ->assertJsonPath('data.featured.1.name', 'Daniel')
            ->assertJsonCount(2, 'data.featured');

        // Saving without the field keeps them; an empty value clears them.
        $this
            ->putJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}", $this->trackForm())
            ->assertStatus(202);

        $this->assertSame([$ada->id, $daniel->id], $this->track->featuredIds());

        $this
            ->putJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}", $this->trackForm(['featured_ids' => '']))
            ->assertStatus(202);

        $this->assertSame([], $this->track->featuredIds());
    }

    public function test_a_track_features_at_most_five_artists(): void
    {
        $ids = Artist::factory()
            ->count(6)
            ->create()
            ->pluck('id')
            ->join(',');

        $this
            ->putJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}", $this->trackForm(['featured_ids' => $ids]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('featured_ids');
    }

    public function test_releases_take_the_studio_tracks_features_and_list_them(): void
    {
        $daniel = Artist::factory()
            ->create(['name' => 'Daniel']);

        $this->track->feature([$daniel->id]);

        $id = $this
            ->postJson('/api/creator/releases', $this->releaseForm())
            ->assertCreated()
            ->json('data.id');

        $release = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);
        $track = $release->tracks()->firstOrFail();

        $this->assertSame([$daniel->id], $this->featuredOn($track));
        $this->assertSame($this->main->id, (int) $track->artist->id);

        $this
            ->putJson("/api/creator/releases/{$id}", $this->releaseForm(['id' => $track->id], ['draft' => false]))
            ->assertStatus(202);

        $release->refresh();

        $this
            ->getJson("/api/releases/{$release->slug}/tracks?with=artist")
            ->assertOk()
            ->assertJsonPath('data.0.artist.name', 'Dave')
            ->assertJsonPath('data.0.featured.0.name', 'Daniel')
            ->assertJsonPath('data.0.featured.0.username', $daniel->username);

        // The featured artist's page lists it under their features, the main artist's doesn't.
        $this
            ->getJson("/api/tracks?filters=features&artist_id={$daniel->username}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $track->id);
        $this
            ->getJson("/api/tracks?filters=features&artist_id={$this->main->username}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_release_form_sets_features_and_the_studio_track_follows(): void
    {
        $daniel = Artist::factory()
            ->create();
        $ada = Artist::factory()
            ->create();

        $id = $this
            ->postJson('/api/creator/releases', $this->releaseForm(['featured_ids' => "{$daniel->id},{$ada->id}"]))
            ->assertCreated()
            ->json('data.id');

        $track = Track::query()
            ->where('release_id', $id)
            ->firstOrFail();

        $this->assertSame([$daniel->id, $ada->id], $this->featuredOn($track));
        $this->assertSame([$daniel->id, $ada->id], $this->track->featuredIds());

        $this
            ->putJson("/api/creator/releases/{$id}", $this->releaseForm(['id' => $track->id, 'featured_ids' => (string) $ada->id]))
            ->assertStatus(202);

        $this->assertSame([$ada->id], $this->featuredOn($track));
    }

    public function test_studio_changes_reach_draft_releases_only(): void
    {
        $daniel = Artist::factory()
            ->create();

        $draftId = $this
            ->postJson('/api/creator/releases', $this->releaseForm())
            ->json('data.id');
        $draft = Track::query()
            ->where('release_id', $draftId)
            ->firstOrFail();

        $this->track->feature([$daniel->id]);

        $this->assertSame([$daniel->id], $this->featuredOn($draft));

        $this
            ->putJson("/api/creator/releases/{$draftId}", $this->releaseForm(['id' => $draft->id], ['draft' => false]))
            ->assertStatus(202);

        $this->track->feature([]);

        $this->assertSame([$daniel->id], $this->featuredOn($draft));
    }

    public function test_changing_the_main_artist_keeps_the_features(): void
    {
        $daniel = Artist::factory()
            ->create();
        $other = Artist::factory()
            ->create(['user_id' => $this->owner->id]);

        $id = $this
            ->postJson('/api/creator/releases', $this->releaseForm(['featured_ids' => (string) $daniel->id]))
            ->json('data.id');
        $track = Track::query()
            ->where('release_id', $id)
            ->firstOrFail();

        $this->track->update(['artist_id' => $other->id]);

        $this->assertSame($other->id, (int) $track->artist()->first()->id);
        $this->assertSame([$daniel->id], $this->featuredOn($track));
    }
}
