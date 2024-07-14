<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    // use RefreshDatabase;

    public function testCanSendCollabRequest(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = Project::inRandomOrder()->first();

        $response = $this->post("/api/projects/{$project->id}/collaborators", [
            'role' => 'vocals'
        ]);
        $response->assertCreated();
    }

    public function testCanSendCollabRequestWithCustomRole(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = Project::inRandomOrder()->first();

        $response = $this->post("/api/projects/{$project->id}/collaborators", [
            'role' => 'custom',
            'custom' => 'dancer',
        ]);
        $response->assertCreated();
    }

    public function testCanListPendingCollabRequests(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = Project::whereHas('collaborators', fn ($q) => $q->whereAccepted(false))->inRandomOrder()->first();

        $response = $this->get("/api/projects/{$project->id}/collaborators", [
            'pending' => true,
        ]);

        $response->assertOk();
        $response->assertJsonIsArray('data');
    }

    public function testCanCancelCollabRequest(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $project = Project::inRandomOrder()->first();

        $response = $this->post("/api/projects/{$project->id}/collaborators", [
            'role' => 'custom',
            'custom' => 'dancer',
        ]);

        $id = $response->collect('data.id')->first();

        $response2 = $this->delete("/api/projects/{$project->id}/collaborators/{$id}");

        $response->assertCreated();
        $response2->assertAccepted();
    }
}
