<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Search should ignore case on every database, including PostgreSQL where LIKE is case-sensitive.
 */
class SearchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_search_ignores_case(): void
    {
        $user = User::factory()->create(['firstname' => 'Zebulon', 'lastname' => 'Quartermaine']);

        foreach (['zebulon', 'QUARTERMAINE', 'zebulon quarter'] as $term) {
            $ids = collect($this->getJson('/api/users?limit=50&search='.urlencode($term))->assertOk()->json('data'))->pluck('id');
            $this->assertContains($user->id, $ids->all(), "Searching for \"$term\"");
        }
    }

    public function test_artist_search_ignores_case(): void
    {
        $artist = Artist::factory()->create(['name' => 'Xylophonic Waves']);

        $ids = collect($this->getJson('/api/artists?limit=50&search=xylophonic')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($artist->id, $ids->all());
    }
}
