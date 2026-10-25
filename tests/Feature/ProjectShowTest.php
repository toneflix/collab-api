<?php

namespace Tests\Feature;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectShowTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $collaborator;

    protected User $stranger;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ConfigurationSeeder::class);

        $this->owner = User::factory()
            ->create();
        $this->collaborator = User::factory()
            ->create();
        $this->stranger = User::factory()
            ->create();

        $this->project = Project::query()
            ->forceCreate([
                'user_id' => $this->owner->id,
                'title' => 'Show test',
                'privacy' => 'public',
            ]);
        $this->project
            ->collaborators()
            ->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'keys', 'accepted' => true]);
        $this->project
            ->collaborators()
            ->forceCreate(['user_id' => $this->stranger->id, 'role' => 'bass', 'accepted' => false]);

        $this->project
            ->tracks()
            ->forceCreate(['title' => 'Open', 'privacy' => 'public']);
        $this->project
            ->tracks()
            ->forceCreate(['title' => 'Hidden', 'privacy' => 'private']);
    }

    public function test_collaber_gets_tracks_and_collaborators_with_the_project(): void
    {
        Sanctum::actingAs($this->collaborator);

        $response = $this->getJson("/api/creator/projects/{$this->project->id}?with=tracks,collaborators,genre,artist");

        $response->assertOk();
        $response->assertJsonCount(2, 'data.tracks');
        $response->assertJsonCount(2, 'data.collaborators');
        $response->assertJsonPath('data.collaborators.0.role', 'keys');
    }

    public function test_others_only_see_public_tracks_and_no_collaborators(): void
    {
        Sanctum::actingAs($this->stranger);

        $response = $this->getJson("/api/projects/{$this->project->id}?with=tracks,collaborators");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.tracks');
        $response->assertJsonPath('data.tracks.0.title', 'Open');
        $response->assertJsonMissingPath('data.collaborators');
    }

    public function test_embedded_tracks_are_bounded(): void
    {
        foreach (range(1, ProjectResource::LIST_LIMIT) as $i) {
            $this->project
                ->tracks()
                ->forceCreate(['title' => "Extra {$i}", 'privacy' => 'public']);
        }

        Sanctum::actingAs($this->owner);

        $this->getJson("/api/creator/projects/{$this->project->id}?with=tracks")
            ->assertOk()
            ->assertJsonCount(ProjectResource::LIST_LIMIT, 'data.tracks');
    }
}
