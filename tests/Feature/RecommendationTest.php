<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Recommendation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeRelease(Genre $genre, int $tracks = 3, bool $draft = false): Release
    {
        return Release::factory()
            ->has(Track::factory()->count($tracks)->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => $draft,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
    }

    /**
     * A user without the random follows the factory gives every new user.
     */
    protected function listener(): User
    {
        $user = User::factory()->create();
        $user->followings()->delete();

        return $user;
    }

    public function test_recommends_unheard_tracks_from_the_genres_a_user_listens_to(): void
    {
        $genre = Genre::factory()->create();
        $published = $this->makeRelease($genre);
        $draft = $this->makeRelease($genre, 1, true);

        $user = $this->listener();
        $heard = $published->tracks->first();
        $heard->stream(120, $user);

        (new Recommendation($user, [Track::class]))->generate();

        $recommended = $user->recommendations()
            ->where('recommendable_type', (new Track)->getMorphClass())
            ->pluck('recommendable_id');

        $this->assertNotContains($heard->id, $recommended);
        $this->assertNotContains($draft->tracks->first()->id, $recommended);
        $this->assertTrue(
            $published->tracks->where('id', '!=', $heard->id)->pluck('id')->intersect($recommended)->isNotEmpty(),
            'Expected other tracks from the same genre to be recommended.'
        );
    }

    public function test_recommends_artists_the_user_does_not_follow_yet(): void
    {
        $genre = Genre::factory()->create();
        $release = $this->makeRelease($genre);
        $other = $this->makeRelease($genre);

        $user = $this->listener();
        $release->tracks->first()->stream(120, $user);

        $followed = $other->tracks->first()->artists->first();
        $user->follow($followed);

        (new Recommendation($user, [Artist::class]))->generate();

        $recommended = $user->recommendations()
            ->where('recommendable_type', (new Artist)->getMorphClass())
            ->pluck('recommendable_id');

        $this->assertNotEmpty($recommended);
        $this->assertNotContains($followed->id, $recommended);
    }

    public function test_recommends_people_followed_by_people_the_user_follows(): void
    {
        [$user, $friend, $friendOfFriend] = User::factory()->count(3)->create();

        // The user factory follows random people, so start from a clean slate.
        $user->followings()->delete();
        $friend->followings()->delete();

        $user->follow($friend);
        $friend->follow($friendOfFriend);

        (new Recommendation($user, [User::class]))->generate();

        $recommended = $user->recommendations()
            ->where('recommendable_type', $user->getMorphClass())
            ->pluck('recommendable_id');

        $this->assertContains($friendOfFriend->id, $recommended);
        $this->assertNotContains($friend->id, $recommended);
        $this->assertNotContains($user->id, $recommended);
    }

    public function test_command_refreshes_recommendations_for_a_user(): void
    {
        $genre = Genre::factory()->create();
        $release = $this->makeRelease($genre);
        $user = $this->listener();
        $release->tracks->first()->stream(120, $user);

        $this->artisan('app:recommend', ['user_ids' => [$user->id]])->assertSuccessful();

        $this->assertTrue($user->recommendations()->exists());
    }

    public function test_playlist_falls_back_to_published_tracks_for_new_listeners(): void
    {
        $this->makeRelease(Genre::factory()->create());

        Sanctum::actingAs($this->listener(), ['*']);

        $this->getJson('api/playlist')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJson(fn ($json) => $json->has('data.0')->etc());
    }

    public function test_album_filter_only_returns_releases_with_more_than_one_track(): void
    {
        $genre = Genre::factory()->create();
        $single = $this->makeRelease($genre, 1);
        $album = $this->makeRelease($genre, 2);

        $ids = collect(
            $this->getJson("api/releases?filters=album&genre={$genre->slug}&limit=50")->assertOk()->json('data')
        )->pluck('id');

        $this->assertContains($album->id, $ids);
        $this->assertNotContains($single->id, $ids);
    }
}
