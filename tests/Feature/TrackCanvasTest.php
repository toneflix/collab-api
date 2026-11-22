<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Track;
use App\Models\TrackCanvas;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class TrackCanvasTest extends TestCase
{
    use DatabaseTransactions;

    protected const REPLICATE = 'https://replicate.test/v1';

    protected const CDN = 'https://replicate.delivery/canvas';

    protected User $owner;

    protected Track $track;

    /** @var list<string> */
    protected array $temp = [];

    protected bool $clipsFail = false;

    /** @var list<array<string, mixed>> */
    protected array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => self::REPLICATE,
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_canvas_credits' => 30, 'ai_video_broll_model' => 'p-video']);

        $this->owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000]);
        $this->track = $this->track($this->owner);

        $this->fakeReplicate();
    }

    protected function tearDown(): void
    {
        array_map(fn(string $file) => @unlink($file), $this->temp);
        AiSettings::flush();

        parent::tearDown();
    }

    public function test_owners_make_a_looping_canvas_from_the_artwork(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/creator/tracks/{$this->track->slug}/canvas", ['brief' => 'Neon rain'])
            ->assertAccepted()
            ->assertJsonPath('data.status', TrackCanvas::DRAWING);

        $canvas = TrackCanvas::query()
            ->where('track_id', $this->track->id)
            ->firstOrFail();

        $this->assertSame(TrackCanvas::READY, $canvas->status);
        $this->assertSame(970, Credits::for($this->owner)->total());
        $this->assertSame("720,1280\n", $this->dimensions($canvas->file));
        $this->assertEqualsWithDelta(6.0, $canvas->duration, 0.1);
        $this->assertTrue(Storage::disk(config('filesystems.default'))->exists($canvas->poster));

        [$still, $motion] = $this->inputs;

        $this->assertSame('9:16', $still['aspect_ratio']);
        $this->assertCount(1, $still['image_input']);
        $this->assertStringContainsString('Neon rain', $still['prompt']);
        $this->assertSame(7, $motion['duration']);

        $this->getJson("/api/tracks/{$this->track->slug}/canvas")
            ->assertOk()
            ->assertJsonPath('data.id', $canvas->id)
            ->assertJsonPath('data.status', TrackCanvas::READY)
            ->assertJsonPath('data.videoUrl', fn(?string $url) => filled($url))
            ->assertJsonPath('data.posterUrl', fn(?string $url) => filled($url));
    }

    public function test_a_failed_canvas_is_refunded_and_the_ready_one_stays(): void
    {
        Sanctum::actingAs($this->owner);

        $uploaded = $this->post("/api/creator/tracks/{$this->track->slug}/canvas/upload", ['clip' => $this->clip(5)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.id');

        $this->clipsFail = true;

        $this->postJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertAccepted();

        $this->assertSame(1000, Credits::for($this->owner)->total());
        $this->getJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertJsonPath('data.status', TrackCanvas::FAILED)
            ->assertJsonPath('data.error', 'The canvas couldn\'t be made.');
        $this->getJson("/api/tracks/{$this->track->slug}/canvas")
            ->assertJsonPath('data.id', $uploaded);
    }

    public function test_owners_can_use_their_own_clip(): void
    {
        Sanctum::actingAs($this->owner);

        $this->post("/api/creator/tracks/{$this->track->slug}/canvas/upload", ['clip' => $this->clip(2)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clip');

        $this->post("/api/creator/tracks/{$this->track->slug}/canvas/upload", ['clip' => $this->clip(12)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.source', TrackCanvas::UPLOAD)
            ->assertJsonPath('data.status', TrackCanvas::READY);

        $canvas = TrackCanvas::query()
            ->where('track_id', $this->track->id)
            ->firstOrFail();

        $this->assertSame("720,1280\n", $this->dimensions($canvas->file));
        $this->assertEqualsWithDelta(TrackCanvas::MAX_SECONDS, $canvas->duration, 0.1);
        $this->assertSame(1000, Credits::for($this->owner)->total());
    }

    public function test_a_new_canvas_replaces_the_old_one_and_removing_deletes_its_files(): void
    {
        Sanctum::actingAs($this->owner);

        $first = $this->post("/api/creator/tracks/{$this->track->slug}/canvas/upload", ['clip' => $this->clip(4)], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->postJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertAccepted();

        $disk = Storage::disk(config('filesystems.default'));
        $second = TrackCanvas::query()
            ->where('track_id', $this->track->id)
            ->sole();

        $this->assertNotSame($first, $second->id);
        $this->assertSame([], $disk->allFiles("ai/canvases/{$first}"));

        $this->deleteJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertAccepted();

        $this->assertSame(0, TrackCanvas::query()->where('track_id', $this->track->id)->count());
        $this->assertSame([], $disk->allFiles("ai/canvases/{$second->id}"));
        $this->getJson("/api/tracks/{$this->track->slug}/canvas")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_only_the_track_owner_changes_its_canvas(): void
    {
        $other = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000]);

        Sanctum::actingAs($other);

        $this->getJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertForbidden();
        $this->postJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertForbidden();
        $this->post("/api/creator/tracks/{$this->track->slug}/canvas/upload", ['clip' => $this->clip(4)], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->deleteJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertForbidden();

        $this->assertSame(1000, Credits::for($other)->total());
    }

    public function test_tracks_without_artwork_cant_get_an_ai_canvas(): void
    {
        Sanctum::actingAs($this->owner);

        $this->track
            ->forceFill(['image' => null])
            ->saveQuietly();

        $this->postJson("/api/creator/tracks/{$this->track->slug}/canvas")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('track');
    }

    public function test_deleting_a_track_deletes_its_canvas_files_and_orphans_are_pruned(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->post("/api/creator/tracks/{$this->track->slug}/canvas/upload", ['clip' => $this->clip(4)], ['Accept' => 'application/json'])
            ->json('data.id');
        $disk = Storage::disk(config('filesystems.default'));

        $this->assertNotEmpty($disk->allFiles("ai/canvases/{$id}"));

        $this->deleteJson("/api/creator/releases/{$this->track->release_id}/tracks/{$this->track->id}")
            ->assertAccepted();

        $this->assertNull(TrackCanvas::query()->find($id));
        $this->assertSame([], $disk->allFiles("ai/canvases/{$id}"));

        $gone = $id + 100;

        $disk->put("ai/canvases/{$gone}/canvas.mp4", 'clip');

        $this->artisan('videos:prune-files')
            ->assertSuccessful();

        $this->assertFalse($disk->exists("ai/canvases/{$gone}/canvas.mp4"));
    }

    protected function subscriber(array $features): User
    {
        $plan = Plan::create([
            'slug' => 'canvas-' . uniqid(), 'name' => 'Canvas', 'price' => 1000, 'interval' => 'month',
            'features' => $features,
        ]);
        $user = User::factory()
            ->create();

        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);

        return $user->fresh();
    }

    /**
     * A released track with square artwork, owned by the user.
     */
    protected function track(User $owner): Track
    {
        $release = Release::query()
            ->forceCreate([
                'user_id' => $owner->id,
                'title' => 'Album',
                'slug' => 'album-' . uniqid(),
                'draft' => false,
            ]);

        $media = new Media;
        $name = 'canvas-' . uniqid() . '.png';
        $art = $this->ffmpeg('png', ['-f', 'lavfi', '-i', 'color=c=orange:s=600x600', '-frames:v', '1']);

        $media->disk->put($media->getPrefix('artwork') . "artwork/{$name}", (string) file_get_contents($art));

        $track = new Track;
        $track->forceFill([
            'release_id' => $release->id,
            'title' => 'Night Drive',
            'slug' => 'night-drive-' . uniqid(),
            'image' => $name,
            'duration' => 12,
        ]);
        $track->saveQuietly();

        return $track;
    }

    protected function fakeReplicate(): void
    {
        $art = (string) file_get_contents($this->ffmpeg('png', ['-f', 'lavfi', '-i', 'color=c=orange:s=600x600', '-frames:v', '1']));
        $still = (string) file_get_contents($this->ffmpeg('png', ['-f', 'lavfi', '-i', 'color=c=purple:s=768x1344', '-frames:v', '1']));
        $clip = (string) file_get_contents($this->ffmpeg('mp4', ['-f', 'lavfi', '-i', 'testsrc=size=432x768:rate=24:duration=7', '-pix_fmt', 'yuv420p']));

        Http::fake(function (Request $request) use ($art, $still, $clip) {
            $url = $request->url();

            if (str_ends_with($url, '/predictions')) {
                $this->inputs[] = $request['input'];
            }

            return match (true) {
                $url === self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/f1']], 201),
                str_ends_with($url, '/seedream-4/predictions') => Http::response(['id' => 's1', 'status' => 'succeeded', 'output' => [self::CDN . '/still.png']], 201),
                str_contains($url, '/prunaai/') && $this->clipsFail => Http::response(['id' => 'c1', 'status' => 'failed', 'error' => 'boom'], 201),
                str_contains($url, '/prunaai/') => Http::response(['id' => 'c1', 'status' => 'succeeded', 'output' => self::CDN . '/clip.mp4'], 201),
                $url === self::CDN . '/still.png' => Http::response($still),
                $url === self::CDN . '/clip.mp4' => Http::response($clip),
                str_contains($url, '/artwork/') => Http::response($art),
                default => Http::response(['detail' => "Unexpected {$url}"], 500),
            };
        });
    }

    /**
     * An uploaded landscape clip this many seconds long.
     */
    protected function clip(int $seconds): UploadedFile
    {
        $path = $this->ffmpeg('mp4', ['-f', 'lavfi', '-i', "testsrc=size=640x360:rate=24:duration={$seconds}", '-pix_fmt', 'yuv420p']);

        return new UploadedFile($path, 'clip.mp4', 'video/mp4', null, true);
    }

    /**
     * Width and height of a stored video, as ffprobe prints them.
     */
    protected function dimensions(string $file): string
    {
        $local = $this->path('mp4');

        file_put_contents($local, Storage::disk(config('filesystems.default'))->get($file));

        $probe = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $local]);
        $probe->mustRun();

        return $probe->getOutput();
    }

    /**
     * A temporary file made by FFmpeg from these input arguments.
     *
     * @param  list<string>  $args
     */
    protected function ffmpeg(string $ext, array $args): string
    {
        $path = $this->path($ext);

        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$args, $path]))
            ->mustRun();

        return $path;
    }

    protected function path(string $ext): string
    {
        $base = tempnam(sys_get_temp_dir(), 'canvas-');
        @unlink($base);
        $path = "{$base}.{$ext}";
        $this->temp[] = $path;

        return $path;
    }
}
