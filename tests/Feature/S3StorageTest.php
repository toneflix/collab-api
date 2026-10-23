<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Runs uploads, links and audio processing against a real S3 API.
 * Set S3_TEST_ENDPOINT (e.g. a local MinIO or moto server) to run it:
 *
 *   S3_TEST_ENDPOINT=http://127.0.0.1:9090 php artisan test --filter S3StorageTest
 */
class S3StorageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = env('S3_TEST_ENDPOINT');

        if (! $endpoint) {
            $this->markTestSkipped('Set S3_TEST_ENDPOINT to run the S3 storage tests.');
        }

        $bucket = 'collab-test-'.strtolower(str()->random(8));

        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'key' => env('S3_TEST_KEY', 'test'),
                'secret' => env('S3_TEST_SECRET', 'test'),
                'region' => env('S3_TEST_REGION', 'us-east-1'),
                'bucket' => $bucket,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'url' => rtrim($endpoint, '/').'/'.$bucket,
                'throw' => true,
            ],
            'toneflix-fileable.temporary_urls' => true,
        ]);

        Storage::forgetDisk('s3');

        (new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => config('filesystems.disks.s3.key'), 'secret' => config('filesystems.disks.s3.secret')],
        ]))->createBucket(['Bucket' => $bucket]);
    }

    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function bucketUrl(): string
    {
        return config('filesystems.disks.s3.url');
    }

    public function test_avatars_are_resized_and_stored_on_s3(): void
    {
        $user = $this->actingAsFresh(User::factory()->create());

        $this->post('/api/account/update/profile/picture', [
            'image' => UploadedFile::fake()->image('me.jpg', 900, 700),
        ])->assertAccepted();

        $user = $user->fresh();
        $key = 'avatars/'.$user->image;

        $this->assertTrue(Storage::disk('s3')->exists($key));
        $this->assertSame([400, 400], array_slice(getimagesizefromstring(Storage::disk('s3')->get($key)), 0, 2));

        // Links are signed, so they work on private buckets too.
        $url = $user->files['image'];
        $this->assertStringStartsWith($this->bucketUrl().'/'.$key, $url);
        $this->assertStringContainsString('X-Amz-Signature', $url);
        $this->assertSame(200, $this->http($url));
    }

    public function test_missing_files_fall_back_to_a_default(): void
    {
        $user = User::factory()->create(['image' => 'not-there.png']);

        $this->assertNotEmpty($user->files['image']);
        $this->assertStringNotContainsString('not-there.png', $user->files['image']);
    }

    public function test_stems_upload_to_s3_and_get_working_links(): void
    {
        $owner = $this->actingAsFresh(User::factory()->create());
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $projectTrack = ProjectTrack::factory()->create(['project_id' => $project->id]);

        $this->post("/api/creator/projects/{$project->id}/tracks/{$projectTrack->id}/stems", [
            'title' => 'Vocals',
            'src' => UploadedFile::fake()->create('vocals.mp3', 200, 'audio/mpeg'),
        ])->assertSuccessful();

        $stem = $projectTrack->stems()->latest('id')->firstOrFail();
        $this->assertTrue(Storage::disk('s3')->exists('media/music/stem/'.$stem->src));

        $links = [['url' => $stem->getFiles['src']['dynamicLink']]];

        // The link goes through the API, which sends the browser to a short-lived S3 link.
        $response = $this->get($links[0]['url']);
        $response->assertRedirect();
        $this->assertStringContainsString('X-Amz-Signature', $response->headers->get('Location'));
        $this->assertSame(200, $this->http($response->headers->get('Location')));
    }

    public function test_uploaded_audio_is_processed_on_s3(): void
    {
        config(['settings.encoding.output' => 'aac', 'settings.encoding.output_codec' => 'aac']);

        $owner = $this->actingAsFresh(User::factory()->create());
        $genre = Genre::factory()->create();
        $artist = Artist::factory()->create();
        $release = Release::factory()->create(['user_id' => $owner->id, 'genre_id' => $genre->id, 'artist_id' => $artist->id]);

        $path = tempnam(sys_get_temp_dir(), 'audio').'.mp3';
        copy(base_path('tests/fixtures/audio.mp3'), $path);

        $id = $this->post("/api/creator/releases/{$release->id}/tracks", [
            'title' => 'On S3',
            'audio' => new UploadedFile($path, 'audio.mp3', 'audio/mp3', null, true),
            'image' => UploadedFile::fake()->image('cover.jpg', 800, 800),
            'privacy' => 'public',
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
        ])->assertCreated()->json('data.id');

        $track = Track::findOrFail($id);

        $this->assertTrue($track->meta['processed'] ?? false, json_encode($track->meta));
        $this->assertGreaterThan(0, $track->duration);
        $this->assertStringEndsWith('.aac', $track->audio);
        $this->assertTrue(Storage::disk('s3')->exists('media/music/tracks/'.$track->audio));
        $this->assertTrue(Storage::disk('s3')->exists('artwork/'.$track->image));
    }

    /**
     * Fetch a URL outside the app and return the status code.
     */
    protected function http(string $url): int
    {
        return Http::withoutRedirecting()->get($url)->status();
    }
}
