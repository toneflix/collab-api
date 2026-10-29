<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExploreListsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $voted;

    protected User $quiet;

    protected Project $votedProject;

    protected Project $releasedProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ConfigurationSeeder::class);

        $this->voted = User::factory()
            ->create();
        $this->quiet = User::factory()
            ->create();

        $this->votedProject = $this->project($this->voted);
        $this->releasedProject = $this->project($this->quiet);

        Release::factory()
            ->create([
                'user_id' => $this->quiet->id,
                'artist_id' => Artist::factory()->create()->id,
                'project_id' => $this->releasedProject->id,
                'draft' => false,
            ]);

        $this->votedProject
            ->socialInteractions()
            ->forceCreate([
                'interactor_type' => User::class,
                'interactor_id' => $this->quiet->id,
                'votes' => 3,
            ]);
    }

    protected function project(User $owner): Project
    {
        return Project::query()
            ->forceCreate([
                'user_id' => $owner->id,
                'title' => "Explore {$owner->id}",
                'privacy' => 'public',
            ]);
    }

    protected function ids(string $uri): array
    {
        return collect($this->getJson($uri)->assertOk()->json('data'))
            ->pluck('id')
            ->all();
    }

    public function test_top_projects_rank_voted_projects_first_and_keep_the_rest(): void
    {
        $ids = $this->ids('/api/projects?filters=top,open&period=month&limit=100');

        $this->assertContains($this->releasedProject->id, $ids);
        $this->assertLessThan(
            array_search($this->releasedProject->id, $ids),
            array_search($this->votedProject->id, $ids),
        );
    }

    public function test_guest_project_shelves_include_released_projects(): void
    {
        $sections = $this
            ->getJson('/api/explore?top_projects=100&new_projects=100&random_projects=100')
            ->assertOk()
            ->json('data');

        foreach (['topProjects', 'newProjects', 'randomProjects'] as $key) {
            $ids = collect($sections[$key]['list'])->pluck('id');

            $this->assertTrue($ids->contains($this->votedProject->id), $key);
            $this->assertTrue($ids->contains($this->releasedProject->id), $key);
        }
    }

    public function test_top_and_new_creators_include_creators_without_votes(): void
    {
        $top = $this->ids('/api/users?filters=creator,top&period=month&limit=100');
        $new = $this->ids('/api/users?filters=creator,latest&limit=100');

        foreach ([$top, $new] as $ids) {
            $this->assertContains($this->voted->id, $ids);
            $this->assertContains($this->quiet->id, $ids);
        }

        $this->assertLessThan(
            array_search($this->quiet->id, $top),
            array_search($this->voted->id, $top),
        );
    }
}
