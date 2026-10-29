<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrackAlbumTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeRelease(int $tracks, array $attributes = [], array $trackAttributes = []): Release
    {
        $genre = Genre::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->count($tracks)->state(['genre_id' => $genre->id, ...$trackAttributes]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
                ...$attributes,
            ]);
    }

    public function test_album_tracks_carry_their_album(): void
    {
        $release = $this->makeRelease(3, ['title' => 'Night Shapes']);

        $response = $this
            ->getJson("api/releases/{$release->slug}/tracks")
            ->assertOk();

        $response->assertJsonPath('data.0.album', [
            'id' => $release->id,
            'slug' => $release->slug,
            'title' => 'Night Shapes',
        ]);
        $response->assertJsonCount(3, 'data');
    }

    public function test_title_track_of_an_album_carries_its_album(): void
    {
        $release = $this->makeRelease(2, ['title' => 'Fracture'], ['title' => 'Fracture']);

        $this
            ->getJson("api/releases/{$release->slug}/tracks")
            ->assertOk()
            ->assertJsonPath('data.0.album.title', 'Fracture');
    }

    public function test_singles_have_no_album(): void
    {
        $release = $this->makeRelease(1, ['title' => 'Fracture'], ['title' => 'Fracture']);
        $track = $release->tracks->first();

        $this
            ->getJson("api/releases/{$release->slug}/tracks")
            ->assertOk()
            ->assertJsonMissingPath('data.0.album');

        $this
            ->getJson("api/releases/{$release->slug}/tracks/{$track->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.album');
    }

    public function test_release_tracks_do_not_query_the_release_per_track(): void
    {
        $release = $this->makeRelease(6);

        DB::enableQueryLog();

        $this
            ->getJson("api/releases/{$release->slug}?with=tracks")
            ->assertOk()
            ->assertJsonPath('data.tracks.0.album.id', $release->id);

        $releaseQueries = collect(DB::getQueryLog())
            ->filter(fn(array $query) => str_contains($query['query'], 'from "releases"'))
            ->count();

        $this->assertLessThanOrEqual(3, $releaseQueries);
    }
}
