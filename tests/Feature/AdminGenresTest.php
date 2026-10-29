<?php

namespace Tests\Feature;

use App\Models\Genre;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminGenresTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->admin = User::factory()
            ->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-content'));
    }

    public function test_people_without_permission_cannot_manage_genres(): void
    {
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this
            ->getJson('/api/admin/genres')
            ->assertForbidden();
        $this
            ->postJson('/api/admin/genres', ['title' => 'Nope'])
            ->assertForbidden();
    }

    public function test_admin_lists_genres_with_usage(): void
    {
        $genre = Genre::query()
            ->create(['title' => 'Zouk Fusion Test']);
        Release::factory()
            ->create(['genre_id' => $genre->id]);

        Sanctum::actingAs($this->admin);

        $this
            ->getJson('/api/admin/genres?search=Zouk Fusion')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Zouk Fusion Test')
            ->assertJsonPath('data.0.releasesCount', 1)
            ->assertJsonPath('data.0.imageUrl', null)
            ->assertJsonStructure(['data', 'meta' => ['currentPage', 'lastPage', 'perPage', 'total']]);
    }

    public function test_a_genre_whose_cover_file_is_missing_has_no_cover(): void
    {
        $genre = Genre::query()
            ->create(['title' => 'Missing Cover Test']);

        $genre
            ->forceFill(['image' => 'not-stored.jpg'])
            ->saveQuietly();

        $this->assertNull($genre->coverUrl());
    }

    public function test_admin_adds_a_genre_with_a_cover(): void
    {
        Sanctum::actingAs($this->admin);

        $this
            ->post('/api/admin/genres', [
                'title' => 'Lo-fi Highlife',
                'description' => 'Slow, warm highlife.',
                'image' => UploadedFile::fake()->image('cover.jpg', 600, 600),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Lo-fi Highlife')
            ->assertJsonPath('data.slug', 'lo-fi-highlife');

        $genre = Genre::query()
            ->where('title', 'Lo-fi Highlife')
            ->firstOrFail();

        $this->assertNotNull($genre->image);
        $this->assertNotNull($genre->coverUrl());

        $this
            ->getJson("/api/genres/{$genre->id}")
            ->assertOk()
            ->assertJsonPath('data.imageUrl', $genre->coverUrl());
    }

    public function test_admin_renames_a_genre_and_removes_its_cover(): void
    {
        Sanctum::actingAs($this->admin);

        $genre = Genre::query()
            ->create(['title' => 'Old Name Test']);

        $this
            ->post("/api/admin/genres/{$genre->id}", [
                '_method' => 'PUT',
                'title' => 'New Name Test',
                'image' => UploadedFile::fake()->image('cover.jpg', 600, 600),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.title', 'New Name Test');

        $this->assertNotNull($genre->refresh()->image);

        $this
            ->putJson("/api/admin/genres/{$genre->id}", ['title' => 'New Name Test', 'remove_image' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.imageUrl', null);

        $this->assertNull($genre->refresh()->image);
    }

    public function test_genres_with_covers_are_listed_first(): void
    {
        Sanctum::actingAs($this->admin);

        $this
            ->post('/api/admin/genres', [
                'title' => 'Zzz Covered Test',
                'image' => UploadedFile::fake()->image('cover.jpg', 600, 600),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        Genre::query()
            ->where('title', '!=', 'Zzz Covered Test')
            ->update(['image' => null]);

        $this
            ->getJson('/api/genres?limit=3')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Zzz Covered Test');
    }

    public function test_titles_stay_unique(): void
    {
        Sanctum::actingAs($this->admin);

        Genre::query()
            ->create(['title' => 'Taken Title Test']);

        $this
            ->postJson('/api/admin/genres', ['title' => 'Taken Title Test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_a_genre_in_use_moves_its_music_before_it_goes(): void
    {
        Sanctum::actingAs($this->admin);

        $genre = Genre::query()
            ->create(['title' => 'Going Away Test']);
        $target = Genre::query()
            ->create(['title' => 'Staying Test']);
        $release = Release::factory()
            ->create(['genre_id' => $genre->id]);
        $fan = User::factory()
            ->create();
        $fan->interests()->attach($genre->id);

        $this
            ->deleteJson("/api/admin/genres/{$genre->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('move_to');

        $this
            ->deleteJson("/api/admin/genres/{$genre->id}", ['move_to' => $target->id])
            ->assertStatus(202)
            ->assertJsonPath('data.movedTo', $target->id);

        $this->assertModelMissing($genre);
        $this->assertSame($target->id, $release->refresh()->genre_id);
        $this->assertTrue($fan->interests()->whereKey($target->id)->exists());
    }

    public function test_an_unused_genre_is_removed_outright(): void
    {
        Sanctum::actingAs($this->admin);

        $genre = Genre::query()
            ->create(['title' => 'Unused Test']);

        $this
            ->deleteJson("/api/admin/genres/{$genre->id}")
            ->assertStatus(202);

        $this->assertModelMissing($genre);
    }
}
