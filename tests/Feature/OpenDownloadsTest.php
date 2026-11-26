<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Artist;
use App\Models\FeatureGrant;
use App\Models\Genre;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Settings\AppConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OpenDownloadsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        AppConfiguration::update(['track_downloads' => true]);

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Cache::forget('configuration::build');

        parent::tearDown();
    }

    protected function creator(bool $feature): User
    {
        $user = User::factory()
            ->create();

        if ($feature) {
            $plan = Plan::create([
                'slug' => 'open-' . uniqid(),
                'name' => 'Open',
                'price' => 500,
                'interval' => 'month',
                'features' => [Feature::OPEN_DOWNLOADS->value => true],
            ]);

            $user
                ->subscriptions()
                ->create([
                    'plan' => $plan->slug,
                    'plan_id' => $plan->id,
                    'amount' => 500,
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]);
        }

        return $user->fresh();
    }

    protected function track(User $owner, bool $open = true, array $release = []): Track
    {
        $genre = Genre::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->count(1)->state(['genre_id' => $genre->id, 'audio' => 'song.mp3', 'open_download' => $open]), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'downloadable' => false,
                'genre_id' => $genre->id,
                'user_id' => $owner->id,
                'artist_id' => Artist::factory()->create()->id,
                ...$release,
            ])
            ->tracks
            ->first();
    }

    protected function admin(string ...$permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = User::factory()
            ->create();
        $admin->givePermissionTo($permissions);

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        Sanctum::actingAs($admin->fresh());

        return $admin;
    }

    public function test_guests_and_free_listeners_download_open_releases(): void
    {
        $track = $this->track($this->creator(true));

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'filename']]);

        $this
            ->getJson("/api/releases/{$track->release->slug}/tracks/{$track->id}")
            ->assertJsonPath('data.downloadable', true)
            ->assertJsonPath('data.openDownload', true)
            ->assertJsonPath('data.freeDownload', true);

        Sanctum::actingAs(User::factory()->create());

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertOk();
    }

    public function test_releases_that_are_not_open_still_need_a_plan(): void
    {
        $track = $this->track($this->creator(true), false, ['downloadable' => true]);

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertForbidden()
            ->assertJsonPath('message', 'Downloads are not included in your plan.');
    }

    public function test_open_downloads_stop_when_the_creator_loses_the_feature(): void
    {
        $owner = $this->creator(true);
        $track = $this->track($owner);

        $owner
            ->subscriptions()
            ->update(['ends_at' => now()->subMinute()]);

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertForbidden();
    }

    public function test_taken_down_and_unpublished_releases_are_not_downloadable(): void
    {
        $owner = $this->creator(true);
        $takenDown = $this->track($owner);
        $takenDown->forceFill(['taken_down_at' => now()])->save();
        $draft = $this->track($owner, true, ['draft' => true]);

        $this
            ->getJson("/api/tracks/{$takenDown->id}/download")
            ->assertNotFound();

        $this
            ->getJson("/api/tracks/{$draft->id}/download")
            ->assertForbidden();
    }

    public function test_an_admin_grant_gives_the_feature_without_a_plan(): void
    {
        $owner = $this->creator(false);
        $track = $this->track($owner);

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertForbidden();

        $this->admin('manage-users', 'manage-plans');

        $this
            ->postJson("/api/admin/users/{$owner->id}/features", ['feature' => 'open_downloads', 'reason' => 'Backfill'])
            ->assertOk()
            ->assertJsonPath('data.featureGrants.0.feature', 'open_downloads')
            ->assertJsonPath('data.plan.isDefault', true);

        $this->assertTrue((bool) $owner->fresh()->feature(Feature::OPEN_DOWNLOADS));
        $this->assertSame('feature.granted', $owner->adminActions()->value('action'));

        $this
            ->deleteJson("/api/admin/users/{$owner->id}/features/open_downloads")
            ->assertOk()
            ->assertJsonCount(0, 'data.featureGrants');

        $this->assertFalse((bool) $owner->fresh()->feature(Feature::OPEN_DOWNLOADS));
    }

    public function test_grants_need_plan_managers_and_end_on_their_date(): void
    {
        $owner = $this->creator(false);

        $this->admin('manage-users');

        $this
            ->postJson("/api/admin/users/{$owner->id}/features", ['feature' => 'open_downloads'])
            ->assertForbidden();

        $this->admin('manage-users', 'manage-plans');

        $this
            ->postJson("/api/admin/users/{$owner->id}/features", ['feature' => 'max_projects'])
            ->assertUnprocessable();

        FeatureGrant::create([
            'user_id' => $owner->id,
            'feature' => Feature::OPEN_DOWNLOADS,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse((bool) $owner->fresh()->feature(Feature::OPEN_DOWNLOADS));
    }

    public function test_creators_need_the_feature_to_open_downloads(): void
    {
        Queue::fake();
        Notification::fake();

        $owner = $this->creator(false);
        $project = Project::factory()
            ->create(['user_id' => $owner->id]);
        $projectTrack = ProjectTrack::factory()
            ->create([
                'project_id' => $project->id,
                'genre_id' => Genre::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);

        Sanctum::actingAs($owner);

        $form = [
            'title' => $project->title,
            'genre_id' => (string) $projectTrack->genre_id,
            'artist_id' => (string) $projectTrack->artist_id,
            'project_id' => $project->id,
            'draft' => true,
            'tracks' => [[
                'title' => $projectTrack->title,
                'genre_id' => (string) $projectTrack->genre_id,
                'artist_id' => (string) $projectTrack->artist_id,
                'project_track_id' => $projectTrack->id,
            ]],
        ];

        $id = $this
            ->postJson('/api/creator/releases', $form)
            ->assertCreated()
            ->json('data.id');

        $trackId = Release::query()
            ->findOrFail($id)
            ->tracks()
            ->value('id');
        $opened = [...$form, 'tracks' => [[...$form['tracks'][0], 'id' => $trackId, 'open_download' => true]]];

        $this
            ->putJson("/api/creator/releases/{$id}", $opened)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tracks.0.open_download');

        FeatureGrant::create(['user_id' => $owner->id, 'feature' => Feature::OPEN_DOWNLOADS]);

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        Sanctum::actingAs($owner->fresh());

        $this
            ->putJson("/api/creator/releases/{$id}", $opened)
            ->assertStatus(202);

        $this->assertTrue(Track::query()->findOrFail($trackId)->open_download);
    }

    public function test_admins_open_downloads_on_tracks_of_creators_with_the_feature(): void
    {
        $with = $this->track($this->creator(true), false);
        $without = $this->track($this->creator(false), false);

        $this->admin('manage-content');

        $this
            ->postJson('/api/admin/content/track/bulk', ['action' => 'open_download', 'ids' => [$with->id, $without->id]])
            ->assertStatus(202)
            ->assertJsonPath('data.changed', 1);

        $this->assertTrue($with->fresh()->open_download);
        $this->assertFalse($without->fresh()->open_download);

        $this
            ->putJson("/api/admin/content/track/{$without->id}", [
                'genre_id' => $without->genre_id,
                'title' => $without->title,
                'open_download' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('open_download');
    }
}
