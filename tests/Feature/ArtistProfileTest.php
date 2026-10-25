<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ArtistProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->owner = User::factory()
            ->create();
        $this->artist = Artist::factory()
            ->create(['user_id' => $this->owner->id, 'verified' => false]);
    }

    protected function edit(array $data = [])
    {
        return $this->post("/api/creator/artists/{$this->artist->id}", [
            '_method' => 'PUT',
            'name' => 'New Name',
            'about' => 'A new bio.',
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    public function test_owner_can_edit_details_and_photos(): void
    {
        Sanctum::actingAs($this->owner);

        $this->edit([
            'image' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
            'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.about', 'A new bio.')
            ->assertJsonPath('data.canEdit', true)
            ->assertJsonPath('data.canVerify', false);

        $this->artist->refresh();
        $this->assertNotNull($this->artist->cover);
        $this->assertStringNotContainsString('http', (string) $this->artist->image);
    }

    public function test_owner_cannot_verify_their_artist(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->edit(['verified' => true])
            ->assertStatus(202);

        $this->assertFalse($this->artist->refresh()->verified);
    }

    public function test_admin_can_edit_and_verify_any_artist(): void
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo(Permission::findOrCreate('manage-content'));
        Sanctum::actingAs($admin);

        $this->edit(['verified' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.canVerify', true);
    }

    public function test_other_users_cannot_edit(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this
            ->edit()
            ->assertForbidden();

        $this->assertNotSame('New Name', $this->artist->refresh()->name);
    }

    public function test_profile_says_who_can_edit(): void
    {
        $this->getJson("/api/artists/{$this->artist->username}")
            ->assertOk()
            ->assertJsonPath('data.canEdit', false)
            ->assertJsonPath('data.coverUrl', null);

        $token = $this->owner->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson("/api/artists/{$this->artist->username}")
            ->assertJsonPath('data.canEdit', true);
    }
}
