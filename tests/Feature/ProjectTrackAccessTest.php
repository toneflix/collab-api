<?php

namespace Tests\Feature;

use App\Broadcasting\Channels;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTrackAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $collaborator;

    protected User $pending;

    protected User $stranger;

    protected Project $project;

    protected ProjectTrack $track;

    protected ProjectTrackStem $stem;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        $this->seed(ConfigurationSeeder::class);

        $this->owner = User::factory()
            ->create();
        $this->collaborator = User::factory()
            ->create();
        $this->pending = User::factory()
            ->create();
        $this->stranger = User::factory()
            ->create();

        $this->project = Project::query()
            ->forceCreate([
                'user_id' => $this->owner->id,
                'title' => 'Access test',
                'privacy' => 'private',
            ]);
        $this->project
            ->collaborators()
            ->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'keys', 'accepted' => true]);
        $this->project
            ->collaborators()
            ->forceCreate(['user_id' => $this->pending->id, 'role' => 'bass', 'accepted' => false]);

        $this->track = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Night Drive', 'privacy' => 'private']);

        Sanctum::actingAs($this->owner);
        $id = $this
            ->addStem('Bass')
            ->assertCreated()
            ->json('data.id');
        $this->stem = ProjectTrackStem::query()
            ->findOrFail($id);
    }

    protected function url(string $path = '', ?ProjectTrack $track = null): string
    {
        $track ??= $this->track;

        return "/api/creator/projects/{$track->project->id}/tracks/{$track->id}{$path}";
    }

    protected function addStem(string $title)
    {
        return $this->post($this->url('/stems'), [
            'title' => $title,
            'src' => UploadedFile::fake()->create("{$title}.mp3", 40, 'audio/mpeg'),
            'meta' => ['gain' => 1, 'start' => 0],
        ], ['Accept' => 'application/json']);
    }

    public function test_outsiders_cannot_add_edit_or_delete_stems(): void
    {
        foreach ([$this->stranger, $this->pending] as $user) {
            Sanctum::actingAs($user);

            $this
                ->addStem('Intruder')
                ->assertForbidden();
            $this
                ->putJson($this->url("/stems/{$this->stem->id}"), ['meta' => ['gain' => 0]])
                ->assertForbidden();
            $this
                ->deleteJson($this->url("/stems/{$this->stem->id}"))
                ->assertForbidden();
        }

        $this->assertSame(1, $this->track->stems()->count());
        $this->assertEquals(1, $this->stem->fresh()->meta['gain']);
    }

    public function test_owner_and_collaborators_can_edit_and_delete_stems(): void
    {
        Sanctum::actingAs($this->collaborator);
        $this
            ->putJson($this->url("/stems/{$this->stem->id}"), ['meta' => ['gain' => 0.5]])
            ->assertAccepted();
        $this->assertEquals(0.5, $this->stem->fresh()->meta['gain']);

        Sanctum::actingAs($this->owner);
        $this
            ->deleteJson($this->url("/stems/{$this->stem->id}"))
            ->assertAccepted();
        $this->assertSoftDeleted($this->stem);
    }

    public function test_outsiders_cannot_change_tracks(): void
    {
        $genre = Genre::query()
            ->firstOrCreate(['title' => 'Test genre'], ['slug' => 'test-genre']);
        $artist = Artist::factory()
            ->create(['user_id' => $this->stranger->id]);
        $form = ['title' => 'Taken', 'genre_id' => (string) $genre->id, 'artist_id' => (string) $artist->id];

        Sanctum::actingAs($this->stranger);

        $this
            ->postJson("/api/creator/projects/{$this->project->id}/tracks", $form)
            ->assertForbidden();
        $this->putJson($this->url(), [
            ...$form,
            'stems' => [['id' => $this->stem->id, 'meta' => ['gain' => 0]]],
        ])->assertForbidden();
        $this
            ->deleteJson($this->url())
            ->assertForbidden();
        $this
            ->postJson($this->url('/assets'), ['file' => UploadedFile::fake()->create('notes.pdf', 10)])
            ->assertForbidden();

        $this->assertSame('Night Drive', $this->track->fresh()->title);
        $this->assertEquals(1, $this->stem->fresh()->meta['gain']);
    }

    public function test_stems_and_tracks_must_belong_to_the_url(): void
    {
        $other = Project::query()
            ->forceCreate([
                'user_id' => $this->stranger->id,
                'title' => 'Someone else',
                'privacy' => 'private',
            ]);
        $theirTrack = $other
            ->tracks()
            ->forceCreate(['title' => 'Theirs', 'privacy' => 'private']);
        $sibling = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Sibling', 'privacy' => 'private']);

        Sanctum::actingAs($this->owner);

        $this
            ->putJson($this->url("/stems/{$this->stem->id}", $sibling), ['meta' => ['gain' => 0]])
            ->assertNotFound();
        $this
            ->deleteJson("/api/creator/projects/{$this->project->id}/tracks/{$theirTrack->id}")
            ->assertNotFound();

        $this->assertEquals(1, $this->stem->fresh()->meta['gain']);
        $this->assertNotNull($theirTrack->fresh());
    }

    public function test_only_people_on_the_project_get_full_stem_audio(): void
    {
        $elsewhere = Project::query()
            ->forceCreate([
                'user_id' => $this->owner->id,
                'title' => 'Elsewhere',
                'privacy' => 'private',
            ]);
        $elsewhere
            ->collaborators()
            ->forceCreate(['user_id' => $this->stranger->id, 'role' => 'drums', 'accepted' => true]);

        $this->assertTrue($this->stem->verifyAccess($this->owner));
        $this->assertTrue($this->stem->verifyAccess($this->collaborator));
        $this->assertFalse($this->stem->verifyAccess($this->pending));
        $this->assertFalse($this->stem->verifyAccess($this->stranger));
        $this->assertFalse($this->stem->verifyAccess(null));
    }

    public function test_private_projects_are_hidden_from_outsiders(): void
    {
        $this->app['auth']->forgetGuards();
        $this
            ->getJson("/api/projects/{$this->project->id}")
            ->assertNotFound();

        foreach ([$this->stranger, $this->pending] as $user) {
            Sanctum::actingAs($user);

            $this
                ->getJson("/api/projects/{$this->project->id}")
                ->assertNotFound();
            $this
                ->getJson("/api/creator/projects/{$this->project->id}/tracks")
                ->assertNotFound();
            $this
                ->getJson($this->url())
                ->assertNotFound();
            $this
                ->getJson($this->url('/stems'))
                ->assertNotFound();
            $this
                ->getJson($this->url("/stems/{$this->stem->id}"))
                ->assertNotFound();
            $this
                ->getJson($this->url('/assets'))
                ->assertNotFound();
            $this->assertFalse(Channels::canView($user, $this->track));
        }

        Sanctum::actingAs($this->collaborator);

        $this
            ->getJson("/api/projects/{$this->project->id}")
            ->assertOk();
        $this
            ->getJson("/api/creator/projects/{$this->project->id}/tracks")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this
            ->getJson($this->url('/stems'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertTrue(Channels::canView($this->collaborator, $this->track));
    }

    public function test_outsiders_see_public_tracks_or_all_when_the_project_makes_them_public(): void
    {
        $this->project->update(['privacy' => 'public']);
        $this->track->update(['privacy' => 'public']);
        $hidden = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Unfinished', 'privacy' => 'private']);

        Sanctum::actingAs($this->stranger);

        $this->getJson("/api/creator/projects/{$this->project->id}/tracks")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->track->id);
        $this->getJson("/api/projects/{$this->project->id}?with=tracks")
            ->assertOk()
            ->assertJsonPath('data.tracksPublic', false)
            ->assertJsonCount(1, 'data.tracks');
        $this
            ->getJson($this->url('/stems'))
            ->assertOk();
        $this
            ->getJson($this->url('', $hidden))
            ->assertNotFound();
        $this->getJson("/api/projects/{$this->project->id}")
            ->assertJsonPath('data.opensStudio', true);
        $this->assertTrue(Gate::forUser($this->stranger)->allows('open', $this->track->refresh()));

        $this->project->update(['tracks_public' => true]);

        $this->getJson("/api/projects/{$this->project->id}?with=tracks")
            ->assertOk()
            ->assertJsonCount(2, 'data.tracks');
        $this
            ->getJson($this->url('', $hidden))
            ->assertOk();

        $this->project->update(['privacy' => 'private']);

        $this
            ->getJson($this->url('', $hidden))
            ->assertNotFound();

        Sanctum::actingAs($this->owner);

        $this
            ->getJson("/api/creator/projects/{$this->project->id}/tracks")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_public_preview_projects_play_tracks_but_keep_the_studio_closed(): void
    {
        $this->project->update(['privacy' => 'public_preview']);
        $this->track->update(['privacy' => 'public']);
        $this->track->refresh();

        Sanctum::actingAs($this->stranger);

        $this->getJson("/api/projects/{$this->project->id}?with=tracks")
            ->assertOk()
            ->assertJsonPath('data.opensStudio', false)
            ->assertJsonCount(1, 'data.tracks');
        $this
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.id', $this->track->id);
        $this
            ->getJson($this->url('/stems'))
            ->assertForbidden();
        $this
            ->getJson($this->url("/stems/{$this->stem->id}"))
            ->assertForbidden();
        $this
            ->getJson($this->url('/assets'))
            ->assertForbidden();
        $this->assertFalse(Gate::forUser($this->stranger)->allows('open', $this->track));
        $this->assertTrue(Channels::canView($this->stranger, $this->track));

        Sanctum::actingAs($this->collaborator);

        $this->getJson("/api/projects/{$this->project->id}")
            ->assertJsonPath('data.opensStudio', true);
        $this
            ->getJson($this->url('/stems'))
            ->assertOk();
        $this->assertTrue(Gate::forUser($this->collaborator)->allows('open', $this->track));
    }
}
