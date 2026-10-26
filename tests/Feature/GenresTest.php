<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GenresTest extends TestCase
{
    use DatabaseTransactions;

    public function test_added_genres_are_listed(): void
    {
        $slugs = array_keys(require database_path('data/genres.php'));

        foreach ($slugs as $slug) {
            $this->assertDatabaseHas('genres', ['slug' => $slug]);
        }

        $this->getJson('/api/genres?limit=100')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'amapiano'])
            ->assertJsonFragment(['title' => 'Afrobeats']);
    }
}
