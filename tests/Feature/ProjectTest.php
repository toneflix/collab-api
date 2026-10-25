<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use DatabaseTransactions;

    protected function project(): Project
    {
        return Project::factory()
            ->create(['user_id' => User::factory()->create()->id]);
    }

    public function test_can_send_collab_request(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = $this->project();

        $response = $this->post("/api/projects/{$project->id}/collaborators", [
            'role' => 'vocals',
        ]);
        $response->assertCreated();
    }

    public function test_can_send_collab_request_with_custom_role(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = $this->project();

        $response = $this->post("/api/projects/{$project->id}/collaborators", [
            'role' => 'custom',
            'custom' => 'dancer',
        ]);
        $response->assertCreated();
    }

    public function test_can_list_pending_collab_requests(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = $this->project();
        $project
            ->collaborators()
            ->create(['role' => 'vocals', 'user_id' => User::factory()->create()->id]);

        $response = $this->get("/api/projects/{$project->id}/collaborators", [
            'pending' => true,
        ]);

        $response->assertOk();
        $response->assertJsonIsArray('data');
    }

    public function test_can_cancel_collab_request(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = $this->project();

        $response = $this->post("/api/projects/{$project->id}/collaborators", [
            'role' => 'custom',
            'custom' => 'dancer',
        ]);

        $id = $response
            ->collect('data.id')
            ->first();

        $response2 = $this->delete("/api/projects/{$project->id}/collaborators/{$id}");

        $response->assertCreated();
        $response2->assertAccepted();
    }
}
