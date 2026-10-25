<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $collaborator;

    protected User $pending;

    protected User $stranger;

    protected Project $private;

    protected Project $public;

    protected Project $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ConfigurationSeeder::class);

        $this->owner = User::factory()
            ->create();
        $this->collaborator = User::factory()
            ->create();
        $this->pending = User::factory()
            ->create();
        $this->stranger = User::factory()
            ->create();

        $this->private = $this->project('private');
        $this->public = $this->project('public');
        $this->preview = $this->project('public_preview');

        $this->private
            ->collaborators()
            ->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'keys', 'accepted' => true]);
        $this->private
            ->collaborators()
            ->forceCreate(['user_id' => $this->pending->id, 'role' => 'bass', 'accepted' => false]);
    }

    protected function project(string $privacy): Project
    {
        return Project::query()
            ->forceCreate([
                'user_id' => $this->owner->id,
                'title' => "Visibility {$privacy}",
                'privacy' => $privacy,
            ]);
    }

    /**
     * Sends the request the way the app does, with a bearer token on a route
     * that has no auth middleware.
     */
    protected function as(?User $user, string $uri)
    {
        $this->app['auth']->forgetGuards();

        $headers = $user ? ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken] : [];

        return $this->getJson($uri, $headers);
    }

    protected function listed(?User $user, string $query = ''): array
    {
        return collect($this->as($user, "/api/projects?creator_id={$this->owner->id}&limit=50{$query}")->assertOk()->json('data'))
            ->pluck('id')
            ->all();
    }

    public function test_people_on_a_private_project_can_open_it_with_a_token(): void
    {
        foreach ([$this->owner, $this->collaborator] as $user) {
            $this
                ->as($user, "/api/projects/{$this->private->id}")
                ->assertOk();
        }

        foreach ([null, $this->stranger, $this->pending] as $user) {
            $this
                ->as($user, "/api/projects/{$this->private->id}")
                ->assertNotFound();
        }
    }

    public function test_listings_only_include_projects_the_viewer_can_open(): void
    {
        $open = [$this->public->id, $this->preview->id];

        foreach ([null, $this->stranger, $this->pending] as $user) {
            $this->assertEqualsCanonicalizing($open, $this->listed($user));
        }

        foreach ([$this->owner, $this->collaborator] as $user) {
            $this->assertEqualsCanonicalizing([...$open, $this->private->id], $this->listed($user));
        }
    }

    public function test_home_feeds_leave_out_private_projects(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->public->id, $this->preview->id],
            $this->listed($this->owner, '&filters=open')
        );

        $feed = $this
            ->as($this->owner, '/api/explore?active_projects=50')
            ->assertOk()
            ->json('data.active_projects.list');

        $this->assertNotContains($this->private->id, collect($feed)->pluck('id')->all());
    }
}
