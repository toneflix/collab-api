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

class TrackMenuTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeRelease(int $tracks = 1, array $attributes = []): Release
    {
        $genre = Genre::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->count($tracks)->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
                ...$attributes,
            ]);
    }

    public function test_saved_tracks_are_listed_for_the_user_who_saved_them(): void
    {
        $release = $this->makeRelease(3);
        $saved = $release->tracks->first();
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this
            ->postJson("api/tracks/{$saved->id}/interactions", ['interaction' => 'save', 'state' => true])
            ->assertAccepted()
            ->assertJsonPath('data.saved', true);

        $this
            ->getJson('api/tracks?filters=saved&with=interaction')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $saved->id)
            ->assertJsonPath('data.0.saved', true);

        $this
            ->getJson('api/explore?saved_tracks=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.savedTracks.list');
    }

    public function test_unsaving_takes_a_track_out_of_saved(): void
    {
        $track = $this
            ->makeRelease()
            ->tracks
            ->first();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("api/tracks/{$track->id}/interactions", ['interaction' => 'save', 'state' => true]);
        $this
            ->postJson("api/tracks/{$track->id}/interactions", ['interaction' => 'save', 'state' => false])
            ->assertJsonPath('data.saved', false);

        $this
            ->getJson('api/tracks?filters=saved')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_credits_list_artists_collaborators_and_release_details(): void
    {
        Queue::fake();

        $owner = User::factory()
            ->create();
        $singer = User::factory()
            ->create(['firstname' => 'Ada', 'lastname' => 'Vox']);
        $pending = User::factory()
            ->create();

        $project = Project::factory()
            ->create(['user_id' => $owner->id]);
        $project
            ->collaborators()
            ->forceCreate(['user_id' => $singer->id, 'role' => 'vocals', 'accepted' => true]);
        $project
            ->collaborators()
            ->forceCreate(['user_id' => $pending->id, 'role' => 'mixing', 'accepted' => false]);

        $projectTrack = ProjectTrack::factory()
            ->create([
                'project_id' => $project->id,
                'genre_id' => Genre::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);

        $release = $this->makeRelease(1, [
            'user_id' => $owner->id,
            'record_label' => 'Night Owl Records',
            'composer' => 'Ada Vox',
            'isrc' => 'NG-TF1-26-00001',
            'publisher' => null,
        ]);
        $track = $release->tracks->first();
        $producer = Artist::factory()
            ->create(['name' => 'Beat Smith']);

        $track->update(['project_track_id' => $projectTrack->id]);
        $track
            ->artists()
            ->attach($producer->id, ['type' => 'producer']);

        $response = $this
            ->getJson("api/tracks/{$track->slug}/credits")
            ->assertOk();

        $response
            ->assertJsonPath('data.artists.0.role', 'Artist')
            ->assertJsonPath('data.artists.1.name', 'Beat Smith')
            ->assertJsonPath('data.artists.1.role', 'Producer')
            ->assertJsonCount(1, 'data.collaborators')
            ->assertJsonPath('data.collaborators.0.username', $singer->username)
            ->assertJsonPath('data.collaborators.0.role', 'Vocals')
            ->assertJsonPath('data.details.recordLabel', 'Night Owl Records')
            ->assertJsonPath('data.details.composer', 'Ada Vox')
            ->assertJsonPath('data.details.isrc', 'NG-TF1-26-00001')
            ->assertJsonMissingPath('data.details.publisher');
    }

    public function test_credits_of_a_missing_track_are_not_found(): void
    {
        $this
            ->getJson('api/tracks/not-a-track/credits')
            ->assertNotFound();
    }
}
