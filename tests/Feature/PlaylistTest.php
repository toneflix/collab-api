<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PlaylistTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<int>
     */
    protected array $releases = [];

    protected function makeRelease(Genre $genre, int $tracks = 1, array $attributes = []): Release
    {
        $release = Release::factory()
            ->has(Track::factory()->count($tracks)->state(['genre_id' => $genre->id, 'bpm' => null]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
                ...$attributes,
            ]);

        $this->releases[] = $release->id;

        return $release;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, int>
     */
    protected function playlist(array $params = [])
    {
        // Retire every other release so results only hold this test's tracks.
        Release::query()
            ->whereNotIn('id', $this->releases)
            ->update(['retired_at' => now()]);

        return collect($this->getJson('api/playlist?' . http_build_query($params))->assertOk()->json('data'))
            ->pluck('id');
    }

    public function test_tracks_like_the_seed_come_first(): void
    {
        $genre = Genre::factory()
            ->create();
        $other = Genre::factory()
            ->create();

        $seed = $this
            ->makeRelease($genre)->tracks
            ->first();
        $alike = $this
            ->makeRelease($genre, 2)->tracks
            ->pluck('id');
        $this->makeRelease($other, 3);

        $ids = $this->playlist(['similar_to' => $seed->id, 'limit' => 10]);

        $this->assertNotContains($seed->id, $ids);
        $this->assertCount(5, $ids);
        $this->assertEqualsCanonicalizing($alike->all(), $ids->take(2)->all());
    }

    public function test_shared_artists_count_as_alike(): void
    {
        $artist = Artist::factory()
            ->create();
        $seed = $this
            ->makeRelease(Genre::factory()->create())->tracks
            ->first();
        $seed
            ->artists()
            ->attach($artist);

        $this->makeRelease(Genre::factory()->create(), 3);
        $byArtist = $this
            ->makeRelease(Genre::factory()->create())->tracks
            ->first();
        $byArtist
            ->artists()
            ->attach($artist);

        $this->assertSame($byArtist->id, $this->playlist(['similar_to' => $seed->id])->first());
    }

    public function test_queued_tracks_are_skipped_until_nothing_else_is_left(): void
    {
        $genre = Genre::factory()
            ->create();
        $seed = $this
            ->makeRelease($genre)->tracks
            ->first();
        $tracks = $this
            ->makeRelease($genre, 3)->tracks
            ->pluck('id');

        $this->assertEquals(
            [$tracks->last()],
            $this->playlist(['similar_to' => $seed->id, 'exclude' => $tracks->take(2)->all()])->all(),
        );

        $ids = $this->playlist(['similar_to' => $seed->id, 'exclude' => $tracks->all()]);

        $this->assertEqualsCanonicalizing($tracks->all(), $ids->all());
    }

    public function test_the_only_track_is_never_returned_for_itself(): void
    {
        $seed = $this
            ->makeRelease(Genre::factory()->create())->tracks
            ->first();

        $this->assertEmpty($this->playlist(['similar_to' => $seed->id, 'exclude' => [$seed->id]]));
    }

    public function test_only_free_tracks_from_live_releases_are_picked(): void
    {
        $genre = Genre::factory()
            ->create();
        $seed = $this
            ->makeRelease($genre)->tracks
            ->first();
        $draft = $this
            ->makeRelease($genre, 1, ['draft' => true])->tracks
            ->first();
        $retired = $this
            ->makeRelease($genre, 1, ['retired_at' => now()])->tracks
            ->first();
        $paid = $this
            ->makeRelease($genre, 1, ['price' => 500])->tracks
            ->first();
        $live = $this
            ->makeRelease($genre)->tracks
            ->first();

        $ids = $this->playlist(['similar_to' => $seed->id]);

        $this->assertEquals([$live->id], $ids->all());
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($retired->id, $ids);
        $this->assertNotContains($paid->id, $ids);
    }

    public function test_released_tracks_from_private_studio_projects_are_picked(): void
    {
        $genre = Genre::factory()
            ->create();
        $seed = $this
            ->makeRelease($genre)->tracks
            ->first();
        $track = $this
            ->makeRelease($genre)->tracks
            ->first();
        $track->update(['privacy' => 'private']);

        $this->assertEquals([$track->id], $this->playlist(['similar_to' => $seed->id])->all());
    }

    public function test_tracks_without_artwork_show_their_release_artwork(): void
    {
        $release = $this->makeRelease(Genre::factory()->create(), 1, ['image' => 'cover.jpg']);
        $track = $release->tracks
            ->first();
        $track->updateQuietly(['image' => null]);

        $this->assertSame($release->files['artwork'], $track->fresh()->artworkUrl());
    }

    public function test_exclusions_are_bounded(): void
    {
        $this->getJson('api/playlist?' . http_build_query(['exclude' => range(1, 101)]))
            ->assertUnprocessable();
    }
}
