<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialInteractionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_liking_returns_the_interaction_state(): void
    {
        $genre = Genre::factory()
            ->create();

        $release = Release::factory()
            ->has(Track::factory()->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);

        $track = $release->tracks->first();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("api/tracks/{$track->id}/interactions", ['interaction' => 'like'])
            ->assertAccepted()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes', 1)
            ->assertJsonPath('data.disliked', false)
            ->assertJsonMissingPath('data.items')
            ->assertJsonMissingPath('data.escapeWhenCastingToString');
    }
}
