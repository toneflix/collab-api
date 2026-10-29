<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PlaylistsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()
            ->create();
        $this->token = $this->owner
            ->createToken('test')
            ->plainTextToken;
    }

    /**
     * @return Collection<int, Track>
     */
    protected function tracks(int $count = 3, array $release = []): Collection
    {
        $genre = Genre::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->count($count)->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
                ...$release,
            ])
            ->tracks;
    }

    protected function asOwner(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->token);
    }

    protected function asGuest(): static
    {
        Auth::forgetGuards();

        return $this->withoutToken();
    }

    protected function makePlaylist(array $attributes = []): Playlist
    {
        return $this->owner
            ->playlists()
            ->create(['title' => 'Late drives', ...$attributes]);
    }

    public function test_a_listener_creates_a_private_playlist(): void
    {
        $response = $this
            ->asOwner()
            ->postJson('api/playlists', ['title' => 'Late drives'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Late drives')
            ->assertJsonPath('data.privacy', 'private')
            ->assertJsonPath('data.tracksCount', 0)
            ->assertJsonPath('data.owned', true);

        $this->assertStringStartsWith('late-drives-', $response->json('data.slug'));
    }

    public function test_tracks_are_added_once_in_order_and_listed(): void
    {
        $playlist = $this->makePlaylist();
        [$first, $second] = $this
            ->tracks(2)
            ->all();

        $this
            ->asOwner()
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $second->id])
            ->assertAccepted()
            ->assertJsonPath('data.tracksCount', 1);
        $this
            ->asOwner()
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $first->id])
            ->assertJsonPath('data.tracksCount', 2);
        $this
            ->asOwner()
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $first->id])
            ->assertAccepted()
            ->assertJsonPath('data.tracksCount', 2)
            ->assertJsonPath('message', 'Already in Late drives.');

        $this
            ->asOwner()
            ->getJson("api/playlists/{$playlist->slug}/tracks")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_the_owners_list_marks_playlists_holding_a_track(): void
    {
        $track = $this
            ->tracks(1)
            ->first();
        $holding = $this->makePlaylist(['title' => 'Holding']);

        $this->makePlaylist(['title' => 'Empty']);
        $holding
            ->tracks()
            ->attach($track->id, ['position' => 1]);

        $response = $this
            ->asOwner()
            ->getJson("api/playlists?track_id={$track->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $marks = collect($response->json('data'))->pluck('hasTrack', 'title');

        $this->assertTrue($marks['Holding']);
        $this->assertFalse($marks['Empty']);
    }

    public function test_removing_a_track_moves_the_cover_on(): void
    {
        $playlist = $this->makePlaylist();
        [$first, $second] = $this
            ->tracks(2)
            ->all();

        $this
            ->asOwner()
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $first->id]);
        $this
            ->asOwner()
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $second->id]);

        $this
            ->asOwner()
            ->deleteJson("api/playlists/{$playlist->slug}/tracks/{$first->id}")
            ->assertAccepted()
            ->assertJsonPath('data.tracksCount', 1);

        $this->assertSame($second->id, $playlist->fresh()->cover_track_id);
    }

    public function test_private_playlists_are_hidden_from_others(): void
    {
        $playlist = $this->makePlaylist();

        $this
            ->asGuest()
            ->getJson("api/playlists/{$playlist->slug}")
            ->assertNotFound();
        $this
            ->asGuest()
            ->getJson("api/playlists/{$playlist->slug}/tracks")
            ->assertNotFound();

        $playlist->update(['privacy' => Playlist::PUBLIC]);

        $this
            ->asGuest()
            ->getJson("api/playlists/{$playlist->slug}")
            ->assertOk()
            ->assertJsonPath('data.owned', false)
            ->assertJsonPath('data.owner.username', $this->owner->username);
    }

    public function test_only_the_owner_changes_a_playlist(): void
    {
        $playlist = $this->makePlaylist(['privacy' => Playlist::PUBLIC]);
        $track = $this
            ->tracks(1)
            ->first();
        $other = User::factory()
            ->create()
            ->createToken('test')
            ->plainTextToken;

        Auth::forgetGuards();

        $this
            ->withToken($other)
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $track->id])
            ->assertNotFound();
        $this
            ->withToken($other)
            ->putJson("api/playlists/{$playlist->slug}", ['title' => 'Mine now'])
            ->assertNotFound();
        $this
            ->withToken($other)
            ->deleteJson("api/playlists/{$playlist->slug}")
            ->assertNotFound();

        $this
            ->asOwner()
            ->putJson("api/playlists/{$playlist->slug}", ['title' => 'Night drives', 'privacy' => 'private'])
            ->assertAccepted()
            ->assertJsonPath('data.title', 'Night drives')
            ->assertJsonPath('data.privacy', 'private');
        $this
            ->asOwner()
            ->deleteJson("api/playlists/{$playlist->slug}")
            ->assertAccepted();

        $this->assertModelMissing($playlist);
    }

    public function test_unreleased_tracks_cannot_be_added(): void
    {
        $playlist = $this->makePlaylist();
        $draft = $this
            ->tracks(1, ['draft' => true])
            ->first();

        $this
            ->asOwner()
            ->postJson("api/playlists/{$playlist->slug}/tracks", ['track_id' => $draft->id])
            ->assertNotFound();
    }
}
