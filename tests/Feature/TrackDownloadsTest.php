<?php

namespace Tests\Feature;

use App\Models\Artist;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class TrackDownloadsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        AppConfiguration::update(['track_downloads' => true]);
    }

    protected function tearDown(): void
    {
        Cache::forget('configuration::build');

        parent::tearDown();
    }

    protected function release(bool $downloadable, float $price = 0, ?User $owner = null): Release
    {
        $genre = Genre::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->count(1)->state(['genre_id' => $genre->id, 'audio' => 'song.mp3']), 'tracks')
            ->create([
                'draft' => false,
                'price' => $price,
                'downloadable' => $downloadable,
                'genre_id' => $genre->id,
                'user_id' => ($owner ?? User::factory()->create())->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
    }

    protected function subscriber(): User
    {
        $plan = Plan::create([
            'slug' => 'downloader',
            'name' => 'Downloader',
            'price' => 500,
            'interval' => 'month',
            'features' => ['downloads' => true],
        ]);

        $user = User::factory()
            ->create();

        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug,
                'plan_id' => $plan->id,
                'amount' => 500,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
            ]);

        return $user->fresh();
    }

    public function test_subscribers_download_tracks_the_creator_allowed(): void
    {
        $track = $this
            ->release(true)->tracks
            ->first();

        Sanctum::actingAs($this->subscriber());

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'filename']]);
    }

    public function test_tracks_the_creator_did_not_allow_cannot_be_downloaded(): void
    {
        $track = $this
            ->release(false)->tracks
            ->first();

        Sanctum::actingAs($this->subscriber());

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertForbidden()
            ->assertJsonPath('message', 'This track is not available for download.');

        $this
            ->getJson("/api/releases/{$track->release->slug}/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('data.downloadable', false);
    }

    public function test_the_admin_setting_turns_downloads_off(): void
    {
        $track = $this
            ->release(true)->tracks
            ->first();

        AppConfiguration::update(['track_downloads' => false]);

        Sanctum::actingAs($this->subscriber());

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertForbidden();

        $this
            ->getJson("/api/releases/{$track->release->slug}/tracks/{$track->id}")
            ->assertJsonPath('data.downloadable', false);
    }

    public function test_owners_and_buyers_download_whatever_the_settings(): void
    {
        $owner = User::factory()
            ->create();
        $track = $this
            ->release(false, 0, $owner)->tracks
            ->first();

        AppConfiguration::update(['track_downloads' => false]);

        Sanctum::actingAs($owner);

        $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertOk();

        $paid = $this
            ->release(false, 1500)->tracks
            ->first();

        Sanctum::actingAs($paid->release->user);

        $this
            ->getJson("/api/releases/{$paid->release->slug}/tracks/{$paid->id}")
            ->assertJsonPath('data.downloadable', true);
    }

    public function test_creators_opt_in_when_publishing(): void
    {
        Queue::fake();
        Notification::fake();

        $owner = User::factory()
            ->create();
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
            ->assertJsonPath('data.downloadable', false)
            ->json('data.id');

        $this
            ->putJson("/api/creator/releases/{$id}", [...$form, 'downloadable' => true])
            ->assertJsonPath('data.downloadable', true);

        $this->assertTrue(Release::find($id)->downloadable);
    }

    public function test_download_links_save_the_file_instead_of_playing_it(): void
    {
        Storage::fake(config('filesystems.default'));

        $track = $this
            ->release(true)->tracks
            ->first();
        $media = new Media;
        $path = ltrim($media->mediaInfo('music_tracks', $track->audio)['path'], '/');

        $media->disk->put($path, 'audio');

        Sanctum::actingAs($this->subscriber());

        $download = $this
            ->getJson("/api/tracks/{$track->id}/download")
            ->assertOk()
            ->json('data');

        $this->assertSame(Str::slug($track->title) . '.mp3', $download['filename']);
        $this->assertStringContainsString('/api/files/download', $download['url']);
        $this->assertStringContainsString('signature=', $download['url']);

        $this
            ->get($download['url'])
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=' . $download['filename']);

        // The link can't be pointed at another file
        $this
            ->get(str_replace('music_tracks', 'stem_files', $download['url']))
            ->assertForbidden();
    }
}
