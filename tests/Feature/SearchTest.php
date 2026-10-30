<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use App\Services\Admin\UserDirectory;
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
        $user = User::factory()
            ->create(['firstname' => 'Zebulon', 'lastname' => 'Quartermaine']);

        foreach (['zebulon', 'QUARTERMAINE', 'zebulon quarter'] as $term) {
            $ids = collect($this->getJson('/api/users?limit=50&search=' . urlencode($term))->assertOk()->json('data'))
                ->pluck('id');
            $this->assertContains($user->id, $ids->all(), "Searching for \"$term\"");
        }
    }

    public function test_user_search_finds_full_names_and_usernames(): void
    {
        $user = User::factory()
            ->create(['firstname' => 'Josephine', 'lastname' => 'Emmanuella', 'username' => 'jo_beats']);

        foreach (['Josephine Emmanuella', 'emmanuella josephine', 'jo_beats', 'josephine jo_beats'] as $term) {
            $this->assertContains($user->id, $this->userIds($term), "Searching for \"$term\"");
        }

        $this->assertNotContains($user->id, $this->userIds('Josephine Nobody'));
    }

    public function test_admin_directory_finds_full_names_and_emails(): void
    {
        $user = User::factory()
            ->create(['firstname' => 'Josephine', 'lastname' => 'Emmanuella', 'email' => 'jemm@example.test']);
        $directory = app(UserDirectory::class);

        foreach (['Josephine Emmanuella', 'jemm@example', (string) $user->id] as $term) {
            $ids = $directory
                ->query(['search' => $term])
                ->limit(50)
                ->pluck('id');

            $this->assertContains($user->id, $ids->all(), "Searching for \"$term\"");
        }
    }

    public function test_artist_search_ignores_case(): void
    {
        $artist = Artist::factory()
            ->create(['name' => 'Xylophonic Waves']);

        $ids = collect($this->getJson('/api/artists?limit=50&search=xylophonic')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertContains($artist->id, $ids->all());
    }

    /**
     * Ids of the accounts the public user search returns for a term.
     */
    private function userIds(string $term): array
    {
        $response = $this
            ->getJson('/api/users?limit=50&search=' . urlencode($term))
            ->assertOk();

        return $response
            ->collect('data')
            ->pluck('id')
            ->all();
    }
}
