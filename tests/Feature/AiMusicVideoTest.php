<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Ai\Video\ShotList;
use App\Services\Composer\Ai\LyricModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class AiMusicVideoTest extends TestCase
{
    use DatabaseTransactions;

    protected const REPLICATE = 'https://replicate.test/v1';

    protected const CDN = 'https://replicate.delivery/video';

    protected User $owner;

    protected Track $track;

    /** @var list<string> */
    protected array $temp = [];

    protected bool $clipsFail = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => self::REPLICATE,
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_video_storyboard_credits' => 20, 'ai_video_credits' => 190]);

        $this->owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000]);
        $this->track = $this->track($this->owner);

        $this->app->instance(LyricModel::class, new class implements LyricModel
        {
            public function ask(string $system, string $prompt, int $maxTokens = 1200): string
            {
                return json_encode([
                    'style' => ['look' => 'Neon night', 'character' => 'A young woman with short braids'],
                    'shots' => [
                        ['start' => 0, 'kind' => 'broll', 'prompt' => 'A city skyline at dusk'],
                        ['start' => 3.1, 'kind' => 'performance', 'prompt' => 'The singer on a rooftop'],
                        ['start' => 6.2, 'kind' => 'broll', 'prompt' => 'Cars streaking past'],
                        ['start' => 8.9, 'kind' => 'performance', 'prompt' => 'The singer under a street light'],
                    ],
                ]);
            }

            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }
        });

        $this->fakeReplicate();
    }

    protected function tearDown(): void
    {
        array_map(fn(string $file) => @unlink($file), $this->temp);
        AiSettings::flush();

        parent::tearDown();
    }

    public function test_a_storyboard_is_planned_on_the_beat_with_a_still_per_shot(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['brief' => 'Night city', 'ratio' => '16:9'])
            ->assertCreated()
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD)
            ->assertJsonPath('data.minutes', 1)
            ->assertJsonCount(4, 'data.shots')
            ->json('data.id');

        $shots = MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->orderBy('position')
            ->get();

        $this->assertSame([0.0, 3.0, 6.0, 9.0], $shots->pluck('start')->all());
        $this->assertSame(12.0, $shots->last()->end);
        $this->assertSame([MusicVideoShot::BROLL, MusicVideoShot::PERFORMANCE], $shots->take(2)->pluck('kind')->all());
        $this->assertTrue($shots->every(fn(MusicVideoShot $shot) => (bool) $shot->keyframe));
        $this->assertSame(980, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && $request['input']['aspect_ratio'] === '16:9'
            && isset($request['input']['image_input'])
            && str_contains($request['input']['prompt'], 'reference image'));
    }

    public function test_rendering_lip_syncs_singing_shots_and_cuts_the_video_over_the_song(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted()
            ->assertJsonPath('data.status', MusicVideo::READY)
            ->assertJsonPath('data.billing', MusicVideo::CREDITS);

        $video = MusicVideo::query()
            ->findOrFail($id);

        $this->assertNotNull($video->file);
        $this->assertSame(790, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/p-video-2-pro-avatar/predictions')
            && isset($request['input']['audio']));
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/prunaai/p-video/predictions')
            && $request['input']['duration'] === 3);

        $local = $this->path('mp4');
        $content = Storage::disk(config('filesystems.default'))
            ->get($video->file);

        file_put_contents($local, $content);

        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=width,height,codec_name', '-of', 'csv=p=0', $local]);
        $probe->mustRun();
        $info = $probe->getOutput();

        $this->assertStringContainsString('h264,1280,720', $info);
        $this->assertStringContainsString('aac', $info);
        $this->assertEqualsWithDelta(12.0, (float) collect(explode("\n", trim($info)))->last(), 0.2);
    }

    public function test_plan_video_minutes_pay_for_the_render_before_credits(): void
    {
        $owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000, Feature::VIDEO_MINUTES->value => 2]);
        $track = $this->track($owner);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/creator/tracks/{$track->slug}/videos")
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted()
            ->assertJsonPath('data.billing', MusicVideo::MINUTES);

        $this->assertSame(980, Credits::for($owner)->total());
        $this->getJson("/api/creator/videos/{$id}")
            ->assertOk()
            ->assertJsonPath('minutesLeft', 1);
        $this->getJson('/api/creator/videos')
            ->assertOk()
            ->assertJsonPath('minutesLeft', 1)
            ->assertJsonPath('ownPhotos', false);
    }

    public function test_a_failed_render_goes_back_to_the_storyboard_and_refunds(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->json('data.id');

        $this->clipsFail = true;

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted();

        $video = MusicVideo::query()
            ->findOrFail($id);

        $this->assertSame(MusicVideo::STORYBOARD, $video->status);
        $this->assertNull($video->billing);
        $this->assertNotNull($video->error);
        $this->assertSame(980, Credits::for($this->owner)->total());
    }

    public function test_shots_can_be_changed_before_rendering(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->json('data');

        $this->putJson("/api/creator/videos/{$video['id']}/shots/{$video['shots'][0]['id']}", ['prompt' => 'A quiet harbour at dawn'])
            ->assertAccepted()
            ->assertJsonPath('data.prompt', 'A quiet harbour at dawn');
    }

    public function test_own_photos_need_the_plan_feature_and_consent(): void
    {
        Sanctum::actingAs($this->owner);
        $photo = UploadedFile::fake()->image('me.jpg', 800, 800);

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['photo' => $photo])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['photo' => $photo, 'consent' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertSame(0, MusicVideo::query()->where('user_id', $this->owner->id)->count());
    }

    public function test_only_the_track_owner_can_make_its_video_and_only_the_maker_can_see_it(): void
    {
        $other = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->json('data.id');

        Sanctum::actingAs($other);

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->assertForbidden();
        $this->getJson("/api/creator/videos/{$id}")
            ->assertForbidden();
    }

    public function test_creators_pick_from_their_own_tracks(): void
    {
        $this->track($this->subscriber([]));

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/creator/tracks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->track->id);
        $this->getJson('/api/creator/tracks?search=nothing-like-this')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_shot_lists_keep_shots_between_the_shortest_and_longest_length(): void
    {
        $shots = (new ShotList(30, 100, 8))->build([
            ['start' => 0, 'kind' => 'broll', 'prompt' => 'Open'],
            ['start' => 0.8, 'kind' => 'performance', 'prompt' => 'Too soon'],
            ['start' => 4, 'kind' => 'performance', 'prompt' => 'Sing'],
        ]);

        $lengths = array_map(fn(array $shot) => $shot['end'] - $shot['start'], $shots);

        $this->assertSame(0.0, $shots[0]['start']);
        $this->assertSame(30.0, end($shots)['end']);
        $this->assertGreaterThanOrEqual(ShotList::MIN_SECONDS, min($lengths));
        $this->assertLessThanOrEqual(8.0, max($lengths));
        $this->assertSame('Open', $shots[0]['prompt']);
        $this->assertSame(MusicVideoShot::PERFORMANCE, end($shots)['kind']);
    }

    /**
     * @param  array<string, bool|int>  $features
     */
    protected function subscriber(array $features): User
    {
        $plan = Plan::create([
            'slug' => 'video-' . uniqid(), 'name' => 'Video', 'price' => 1000, 'interval' => 'month',
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
     * A released 12 second track at 120 BPM owned by the user.
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
        $name = 'video-' . uniqid() . '.mp3';
        $audio = $this->ffmpeg('mp3', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(220*2*PI*t):s=44100:d=12']);
        $path = $media->mediaInfo('music_tracks', $name)['path'];
        $bytes = (string) file_get_contents($audio);

        $media->disk->put($path, $bytes);

        $track = new Track;
        $track->forceFill([
            'release_id' => $release->id,
            'title' => 'Night Drive',
            'slug' => 'night-drive-' . uniqid(),
            'audio' => $name,
            'duration' => 12,
            'bpm' => 120,
        ]);
        $track->saveQuietly();

        return $track;
    }

    protected function fakeReplicate(): void
    {
        $still = (string) file_get_contents($this->ffmpeg('png', ['-f', 'lavfi', '-i', 'color=c=purple:s=1344x768', '-frames:v', '1']));
        $clip = (string) file_get_contents($this->ffmpeg('mp4', ['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=24:duration=2', '-pix_fmt', 'yuv420p']));
        $vocals = (string) file_get_contents($this->ffmpeg('wav', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(440*2*PI*t):s=44100:d=12']));

        Http::fake(function (Request $request) use ($still, $clip, $vocals) {
            $url = $request->url();

            return match (true) {
                $url === self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/f1']], 201),
                str_ends_with($url, '/seedream-4/predictions') => Http::response(['id' => 's1', 'status' => 'succeeded', 'output' => [self::CDN . '/still.png']], 201),
                $url === self::REPLICATE . '/predictions' => Http::response(['id' => 'd1', 'status' => 'succeeded', 'output' => ['vocals' => self::CDN . '/vocals.wav']], 201),
                str_contains($url, '/prunaai/') && $this->clipsFail => Http::response(['id' => 'c1', 'status' => 'failed', 'error' => 'boom'], 201),
                str_contains($url, '/prunaai/') => Http::response(['id' => 'c1', 'status' => 'succeeded', 'output' => self::CDN . '/clip.mp4'], 201),
                $url === self::CDN . '/still.png' => Http::response($still),
                $url === self::CDN . '/clip.mp4' => Http::response($clip),
                $url === self::CDN . '/vocals.wav' => Http::response($vocals),
                default => Http::response(['detail' => "Unexpected {$url}"], 500),
            };
        });
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
        $base = tempnam(sys_get_temp_dir(), 'mv-');
        @unlink($base);
        $path = "{$base}.{$ext}";
        $this->temp[] = $path;

        return $path;
    }
}
