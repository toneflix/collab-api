<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\AudioFingerprint;
use App\Models\Comment;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Sound;
use App\Models\Track;
use App\Models\User;
use App\Services\Sounds\SoundSettings;
use App\Services\Studio\StemFiles;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

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

        $bucket = 'collab-test-' . strtolower(str()->random(8));

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
                'url' => rtrim($endpoint, '/') . '/' . $bucket,
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
        $key = 'avatars/' . $user->image;

        $this->assertTrue(Storage::disk('s3')->exists($key));
        $this->assertSame([400, 400], array_slice(getimagesizefromstring(Storage::disk('s3')->get($key)), 0, 2));

        // Links are signed, so they work on private buckets too.
        $url = $user->files['image'];
        $this->assertStringStartsWith($this->bucketUrl() . '/' . $key, $url);
        $this->assertStringContainsString('X-Amz-Signature', $url);
        $this->assertSame(200, $this->http($url));
    }

    public function test_links_are_built_without_asking_the_bucket(): void
    {
        $user = User::factory()
            ->create(['image' => 'not-there.png']);

        // Stored names are trusted on S3, so a missing file gets a link that answers 404.
        $url = $user->files['image'];
        $this->assertStringStartsWith($this->bucketUrl() . '/avatars/not-there.png', $url);
        $this->assertSame(404, $this->http($url));
    }

    public function test_stems_upload_to_s3_and_get_working_links(): void
    {
        [$project, $projectTrack] = $this->projectTrack($this->actingAsFresh(User::factory()->create()));

        $this->post("/api/creator/projects/{$project->id}/tracks/{$projectTrack->id}/stems", [
            'title' => 'Vocals',
            'src' => UploadedFile::fake()->create('vocals.mp3', 200, 'audio/mpeg'),
        ])->assertSuccessful();

        $stem = $projectTrack
            ->stems()
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue(Storage::disk('s3')->exists('media/music/stem/' . $stem->src));

        $this->assertLoadsFromS3($stem->getFiles['src']['dynamicLink']);
    }

    public function test_track_previews_upload_to_s3(): void
    {
        [$project, $projectTrack] = $this->projectTrack($this->actingAsFresh(User::factory()->create()));

        $this->post("/api/creator/projects/{$project->id}/tracks/{$projectTrack->id}", [
            '_method' => 'PUT',
            'title' => 'Mixed down',
            'genre_id' => (string) $projectTrack->genre_id,
            'artist_id' => (string) $projectTrack->artist_id,
            'preview' => UploadedFile::fake()->create('mix.mp3', 120, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $projectTrack->refresh();
        $this->assertTrue(Storage::disk('s3')->exists('media/music/tracks/' . $projectTrack->preview));
        $this->assertLoadsFromS3($projectTrack->getFiles['preview']['dynamicLink']);
    }

    public function test_stems_made_by_tools_are_written_to_and_read_from_s3(): void
    {
        $owner = User::factory()
            ->create();
        [, $projectTrack] = $this->projectTrack($owner);
        $files = app(StemFiles::class);

        $stem = $files->addStem($projectTrack, $owner, base_path('tests/fixtures/audio.mp3'), 'Split vocals');
        $this->assertTrue(Storage::disk('s3')->exists('media/music/stem/' . $stem->src));
        $this->assertSame(filesize(base_path('tests/fixtures/audio.mp3')), $stem->fileSize('src'));
        $this->assertSame('audio/mpeg', $stem->fileMime('src'));

        $local = $files->local($stem);
        $this->assertFileEquals(base_path('tests/fixtures/audio.mp3'), $local);
        unlink($local);
    }

    public function test_voice_notes_are_stored_on_s3(): void
    {
        $owner = $this->actingAsFresh(User::factory()->create());
        [, $projectTrack] = $this->projectTrack($owner);
        $url = "/api/conversations/project-track/{$projectTrack->id}/messages";

        $id = $this->post($url, [
            'voice' => UploadedFile::fake()->create('note.webm', 40, 'audio/webm'),
            'duration' => 4,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $path = (new Media)->mediaInfo('voice_notes', Comment::find($id)->meta['voice']['file'])['path'];
        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertLoadsFromS3($this->getJson($url)->json('data.0.voice.url'));

        $this
            ->deleteJson("{$url}/{$id}")
            ->assertStatus(202);
        $this->assertFalse(Storage::disk('s3')->exists($path));
    }

    public function test_sounds_are_stored_on_s3_and_copied_into_tracks(): void
    {
        SoundSettings::flush();
        $owner = $this->actingAsFresh(User::factory()->create());
        [$project, $projectTrack] = $this->projectTrack($owner);

        $sound = $this->post('/api/sounds/mine', [
            'file' => UploadedFile::fake()->create('loop.mp3', 30, 'audio/mpeg'),
            'duration' => 2,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $file = Sound::findOrFail($sound['id'])->file;
        $this->assertTrue(Storage::disk('s3')->exists('media/sounds/' . $file));
        $this->assertLoadsFromS3($sound['url']);

        $stemId = $this->postJson("/api/creator/projects/{$project->id}/tracks/{$projectTrack->id}/sounds", ['sound_id' => $sound['id']])
            ->assertCreated()
            ->json('data.id');

        $stem = $projectTrack
            ->stems()
            ->findOrFail($stemId);
        $this->assertSame(
            Storage::disk('s3')->get('media/sounds/' . $file),
            Storage::disk('s3')->get('media/music/stem/' . $stem->src)
        );
    }

    public function test_uploaded_audio_is_processed_on_s3(): void
    {
        config(['settings.encoding.output' => 'aac', 'settings.encoding.output_codec' => 'aac']);

        $owner = $this->actingAsFresh(User::factory()->create());
        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create();
        $release = Release::factory()
            ->create(['user_id' => $owner->id, 'genre_id' => $genre->id, 'artist_id' => $artist->id]);

        // MP3 uploads are kept as they are, so upload WAV to have it encoded.
        $path = tempnam(sys_get_temp_dir(), 'audio') . '.wav';
        exec('ffmpeg -loglevel error -y -i ' . escapeshellarg(base_path('tests/fixtures/audio.mp3')) . ' ' . escapeshellarg($path));

        $id = $this->post("/api/creator/releases/{$release->id}/tracks", [
            'title' => 'On S3',
            'audio' => new UploadedFile($path, 'audio.wav', 'audio/wav', null, true),
            'image' => UploadedFile::fake()->image('cover.jpg', 800, 800),
            'privacy' => 'public',
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
        ])->assertCreated()->json('data.id');

        $track = Track::findOrFail($id);

        $this->assertTrue($track->meta['processed'] ?? false, json_encode($track->meta));
        $this->assertGreaterThan(0, $track->duration);
        $this->assertStringEndsWith('.aac', $track->audio);
        $this->assertTrue(Storage::disk('s3')->exists('media/music/tracks/' . $track->audio));
        $this->assertTrue(Storage::disk('s3')->exists('artwork/' . $track->image));

        // The copy check reads the processed file back from the bucket.
        $fingerprint = AudioFingerprint::query()
            ->where('model_type', $track->getMorphClass())
            ->where('model_id', $track->id)
            ->firstOrFail();
        $this->assertSame(hash('sha256', Storage::disk('s3')->get('media/music/tracks/' . $track->audio)), $fingerprint->hash);
    }

    /**
     * A project with one track, owned by the given user.
     *
     * @return array{0: Project, 1: ProjectTrack}
     */
    protected function projectTrack(User $owner): array
    {
        Artist::factory()
            ->create();
        Genre::factory()
            ->create();

        $project = Project::factory()
            ->create(['user_id' => $owner->id]);
        $projectTrack = ProjectTrack::factory()
            ->create(['project_id' => $project->id]);

        return [$project, $projectTrack];
    }

    /**
     * The link loads the file from S3: either it is a signed bucket link, or it goes
     * through the API, which sends the browser on to one that other origins can load.
     */
    protected function assertLoadsFromS3(string $url): void
    {
        if (str_starts_with($url, $this->bucketUrl())) {
            $this->assertStringContainsString('X-Amz-Signature', $url);
            $this->assertSame(200, $this->http($url));

            return;
        }

        $response = $this->get($url);
        $response->assertRedirect();
        $response->assertHeader('Access-Control-Allow-Origin', '*');

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('X-Amz-Signature', $location);
        $this->assertSame(200, $this->http($location));
    }

    /**
     * Fetch a URL outside the app and return the status code.
     */
    protected function http(string $url): int
    {
        return Http::withoutRedirecting()
            ->get($url)
            ->status();
    }
}
