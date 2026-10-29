<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\Release;
use App\Models\Song;
use App\Models\Track;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminContentTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $owner;

    protected Artist $artist;

    protected Genre $genre;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Bus::fake();

        $this->admin = User::factory()
            ->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-content'));
        $this->owner = User::factory()
            ->create(['firstname' => 'Ada', 'lastname' => 'Owner']);
        $this->artist = Artist::factory()
            ->create(['user_id' => $this->owner->id, 'name' => 'Catalog Test Artist']);
        $this->genre = Genre::query()
            ->firstOrFail();
    }

    public function test_people_without_permission_cannot_manage_content(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->getJson('/api/admin/content/release')
            ->assertForbidden();
        $this
            ->postJson('/api/admin/content/artist', ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_unknown_kinds_are_not_found(): void
    {
        Sanctum::actingAs($this->admin);

        $this
            ->getJson('/api/admin/content/comment')
            ->assertNotFound();
    }

    public function test_admin_lists_content_with_owner_and_delete_name(): void
    {
        $release = Release::factory()
            ->create(['user_id' => $this->owner->id, 'artist_id' => $this->artist->id, 'title' => 'Listed Catalog Album', 'draft' => true]);

        Sanctum::actingAs($this->admin);

        $this
            ->getJson('/api/admin/content/release?search=Listed Catalog&status=draft')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $release->id)
            ->assertJsonPath('data.0.type', 'release')
            ->assertJsonPath('data.0.deleteName', 'Listed Catalog Album')
            ->assertJsonPath('data.0.owner.id', $this->owner->id)
            ->assertJsonPath('data.0.fields.artistName', 'Catalog Test Artist')
            ->assertJsonPath('data.0.fields.published', false);

        $this
            ->getJson('/api/admin/content/release?search=Listed Catalog&status=live')
            ->assertJsonPath('meta.total', 0);

        $this
            ->getJson("/api/admin/content/release?search=Listed Catalog&user_id={$this->owner->id}")
            ->assertJsonPath('meta.total', 1);

        $this
            ->getJson("/api/admin/content/release?search=Listed Catalog&user_id={$this->admin->id}")
            ->assertJsonPath('meta.total', 0);
    }

    public function test_admin_adds_and_publishes_an_album_for_someone(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this
            ->post('/api/admin/content/release', [
                'user_id' => $this->owner->id,
                'artist_id' => $this->artist->id,
                'genre_id' => $this->genre->id,
                'title' => 'Admin Made Album',
                'published' => '1',
                'artwork' => UploadedFile::fake()->image('cover.jpg', 600, 600),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Admin Made Album')
            ->assertJsonPath('data.fields.published', true);

        $release = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($response->json('data.id'));

        $this->assertSame($this->owner->id, (int) $release->user_id);
        $this->assertFalse($release->draft);
        $this->assertNotNull($release->image);
        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $this->admin->id,
            'user_id' => $this->owner->id,
            'action' => 'content.created',
        ]);
    }

    public function test_admin_adds_a_track_with_audio_to_an_album(): void
    {
        $release = Release::factory()
            ->create(['user_id' => $this->owner->id, 'artist_id' => $this->artist->id]);

        Sanctum::actingAs($this->admin);

        $this
            ->post('/api/admin/content/track', [
                'release_id' => $release->id,
                'genre_id' => $this->genre->id,
                'title' => 'Admin Made Track',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audio');

        $audio = UploadedFile::fake()->createWithContent('song.mp3', file_get_contents(base_path('tests/fixtures/audio.mp3')));

        $response = $this
            ->post('/api/admin/content/track', [
                'release_id' => $release->id,
                'genre_id' => $this->genre->id,
                'title' => 'Admin Made Track',
                'audio' => $audio,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.fields.releaseId', $release->id)
            ->assertJsonPath('data.fields.artistId', $this->artist->id)
            ->assertJsonPath('data.owner.id', $this->owner->id);

        $track = Track::query()
            ->withoutGlobalScopes()
            ->findOrFail($response->json('data.id'));

        $this->assertNotNull($track->audio);
    }

    public function test_admin_edits_a_track(): void
    {
        $release = Release::factory()
            ->create(['user_id' => $this->owner->id, 'artist_id' => $this->artist->id]);
        $track = Track::factory()
            ->create(['release_id' => $release->id, 'title' => 'Before']);

        Sanctum::actingAs($this->admin);

        $this
            ->putJson("/api/admin/content/track/{$track->id}", [
                'title' => 'After',
                'genre_id' => $this->genre->id,
                'privacy' => 'private',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.title', 'After')
            ->assertJsonPath('data.fields.privacy', 'private');

        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $this->owner->id,
            'action' => 'content.updated',
        ]);
    }

    public function test_admin_writes_and_publishes_a_composition(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this
            ->postJson('/api/admin/content/song', [
                'user_id' => $this->owner->id,
                'title' => 'Admin Lyrics',
                'body' => "First line\nSecond line",
                'published' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fields.published', true);

        $song = Song::query()->findOrFail($response->json('data.id'));

        $this->assertSame($this->owner->id, (int) $song->user_id);
        $this->assertNotNull($song->slug);

        $this
            ->putJson("/api/admin/content/song/{$song->id}", [
                'title' => 'Admin Lyrics',
                'body' => "First line\nA new second line",
                'published' => false,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.fields.published', false)
            ->assertJsonPath('data.fields.body', "First line\nA new second line");
    }

    public function test_admin_adds_and_edits_a_project(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this
            ->postJson('/api/admin/content/project', [
                'user_id' => $this->owner->id,
                'title' => 'Admin Project',
                'privacy' => 'public',
            ])
            ->assertCreated();

        $project = Project::query()
            ->withoutGlobalScopes()
            ->findOrFail($response->json('data.id'));

        $this->assertSame($this->owner->id, (int) $project->user_id);

        $this
            ->putJson("/api/admin/content/project/{$project->id}", [
                'title' => 'Renamed Project',
                'privacy' => 'private',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.title', 'Renamed Project')
            ->assertJsonPath('data.fields.privacy', 'private');
    }

    public function test_admin_adds_an_unclaimed_artist_and_verifies_it(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this
            ->post('/api/admin/content/artist', [
                'name' => 'Unclaimed Admin Artist',
                'image' => UploadedFile::fake()->image('photo.jpg', 400, 400),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.fields.verified', false);

        $id = $response->json('data.id');

        $this
            ->putJson("/api/admin/content/artist/{$id}", ['name' => 'Unclaimed Admin Artist', 'verified' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.fields.verified', true);

        $this
            ->getJson('/api/admin/content/artist?status=unclaimed&search=Unclaimed Admin')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_artists_are_deleted_through_moderation(): void
    {
        $secret = Totp::secret();
        $this->admin
            ->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])
            ->save();

        Sanctum::actingAs($this->admin);

        $this
            ->deleteJson("/api/admin/moderation/items/artist/{$this->artist->id}", [
                'reason' => 'spam',
                'confirm_name' => 'Catalog Test Artist',
                'code' => Totp::code($secret, Totp::step()),
            ])
            ->assertAccepted();

        $this->assertNull(Artist::query()->withoutGlobalScopes()->find($this->artist->id));
        $this->assertDatabaseHas('admin_actions', [
            'user_id' => $this->owner->id,
            'action' => 'content.deleted',
        ]);
    }

    public function test_admin_finds_owners(): void
    {
        Sanctum::actingAs($this->admin);

        $this
            ->getJson("/api/admin/content/owners?search={$this->owner->username}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->owner->id);
    }
}
