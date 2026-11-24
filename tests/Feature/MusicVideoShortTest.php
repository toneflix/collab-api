<?php

namespace Tests\Feature;

use App\Models\MusicVideo;
use App\Models\MusicVideoShort;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MusicVideoShortTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected MusicVideo $video;

    /** @var list<string> */
    protected array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->owner = User::factory()
            ->create();
        $this->video = $this->readyVideo($this->owner);
    }

    protected function tearDown(): void
    {
        array_map(fn(string $file) => @unlink($file), $this->temp);

        parent::tearDown();
    }

    public function test_a_short_is_cut_vertical_from_the_finished_video(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this
            ->postJson("/api/creator/videos/{$this->video->id}/shorts", ['start' => 2, 'end' => 8, 'focus' => 0.25])
            ->assertCreated()
            ->assertJsonPath('data.status', MusicVideoShort::READY)
            ->assertJsonPath('data.length', 6);

        $short = MusicVideoShort::query()
            ->findOrFail($response->json('data.id'));

        $this->assertSame("stream,video,720,1280\nstream,audio\nformat,6.000000", $this->probe($short->file));
        $this->assertNotNull($response->json('data.downloadUrl'));

        $this
            ->getJson("/api/creator/videos/{$this->video->id}/shorts")
            ->assertOk()
            ->assertJsonPath('data.0.id', $short->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_signed_link_streams_or_downloads_the_short(): void
    {
        $short = $this->cutShort();

        $this
            ->get(URL::temporarySignedRoute('ai.videos.short', now()->addHour(), ['video' => $this->video->id, 'short' => $short->id]))
            ->assertOk();
        $this
            ->get(URL::temporarySignedRoute('ai.videos.short', now()->addHour(), ['video' => $this->video->id, 'short' => $short->id, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename={$this->video->track->slug}-short-{$short->id}.mp4");
        $this
            ->get("/api/ai/videos/{$this->video->id}/shorts/{$short->id}/file")
            ->assertForbidden();
    }

    public function test_shorts_run_five_to_sixty_seconds_of_a_finished_video(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->postJson("/api/creator/videos/{$this->video->id}/shorts", ['start' => 0, 'end' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');

        $this->video
            ->forceFill(['status' => MusicVideo::RENDERING])
            ->save();

        $this
            ->postJson("/api/creator/videos/{$this->video->id}/shorts", ['start' => 0, 'end' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('video');
    }

    public function test_a_short_ends_with_the_video(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->postJson("/api/creator/videos/{$this->video->id}/shorts", ['start' => 4, 'end' => 40])
            ->assertCreated()
            ->assertJsonPath('data.end', 12);
    }

    public function test_only_the_owner_makes_and_deletes_shorts(): void
    {
        $short = $this->cutShort();

        Sanctum::actingAs(User::factory()->create());

        $this
            ->postJson("/api/creator/videos/{$this->video->id}/shorts", ['start' => 0, 'end' => 10])
            ->assertForbidden();
        $this
            ->deleteJson("/api/creator/videos/{$this->video->id}/shorts/{$short->id}")
            ->assertForbidden();

        Sanctum::actingAs($this->owner);

        $this
            ->deleteJson("/api/creator/videos/{$this->video->id}/shorts/{$short->id}")
            ->assertAccepted();

        $this->assertModelMissing($short);
        Storage::disk(config('filesystems.default'))->assertMissing($short->file);
    }

    protected function cutShort(): MusicVideoShort
    {
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson("/api/creator/videos/{$this->video->id}/shorts", ['start' => 0, 'end' => 6])
            ->assertCreated()
            ->json('data.id');

        return MusicVideoShort::query()
            ->findOrFail($id);
    }

    /**
     * A finished 12 second 16:9 video for a track the user released.
     */
    protected function readyVideo(User $owner): MusicVideo
    {
        $release = Release::query()
            ->forceCreate([
                'user_id' => $owner->id,
                'title' => 'Album',
                'slug' => 'album-' . uniqid(),
                'draft' => false,
            ]);

        $track = new Track;
        $track->forceFill([
            'release_id' => $release->id,
            'title' => 'Night Drive',
            'slug' => 'night-drive-' . uniqid(),
            'duration' => 12,
        ]);
        $track->saveQuietly();

        $local = $this->ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=24:duration=12',
            '-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(220*2*PI*t):s=44100:d=12',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest',
        ]);
        $video = MusicVideo::query()
            ->create([
                'user_id' => $owner->id,
                'track_id' => $track->id,
                'status' => MusicVideo::READY,
                'duration' => 12,
            ]);
        $file = "ai/videos/{$video->id}/video.mp4";

        Storage::disk(config('filesystems.default'))
            ->put($file, (string) file_get_contents($local));

        $video
            ->forceFill(['file' => $file])
            ->save();

        return $video;
    }

    /**
     * Streams and length of a stored video, as ffprobe prints them.
     */
    protected function probe(string $file): string
    {
        $local = $this->path();
        $content = Storage::disk(config('filesystems.default'))
            ->get($file);

        file_put_contents($local, $content);

        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,width,height', '-show_entries', 'format=duration', '-of', 'csv', $local]);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $args
     */
    protected function ffmpeg(array $args): string
    {
        $path = $this->path();

        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$args, $path]))
            ->mustRun();

        return $path;
    }

    protected function path(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'short-');
        @unlink($base);
        $path = "{$base}.mp4";
        $this->temp[] = $path;

        return $path;
    }
}
