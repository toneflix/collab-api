<?php

namespace Tests\Feature;

use App\Enums\VideoCandidate;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\Video\VideoTrial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class AiVideoTrialTest extends TestCase
{
    use DatabaseTransactions;

    protected const REPLICATE = 'https://replicate.test/v1';

    protected const CDN = 'https://replicate.delivery/video';

    /** @var list<string> */
    protected array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => self::REPLICATE,
        ]);
    }

    protected function tearDown(): void
    {
        array_map(fn(string $file) => @unlink($file), $this->temp);

        parent::tearDown();
    }

    public function test_each_model_renders_the_window_with_the_song_under_it(): void
    {
        $track = $this->track();
        $image = $this->file('png', ['-f', 'lavfi', '-i', 'color=c=red:s=1024x1024', '-frames:v', '1']);
        $clip = $this->file('mp4', ['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=24:duration=3', '-pix_fmt', 'yuv420p']);
        $vocal = $this->file('wav', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(440*2*PI*t):s=44100:d=4']);
        $vocalBytes = (string) file_get_contents($vocal);
        $clipBytes = (string) file_get_contents($clip);

        Http::fake([
            self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/f1']], 201),
            self::REPLICATE . '/predictions' => Http::response([
                'id' => 'split',
                'status' => 'succeeded',
                'output' => ['vocals' => self::CDN . '/vocals.wav'],
            ], 201),
            self::REPLICATE . '/models/*/predictions' => Http::response([
                'id' => 'clip',
                'status' => 'succeeded',
                'output' => self::CDN . '/clip.mp4',
            ], 201),
            self::CDN . '/vocals.wav' => Http::response($vocalBytes),
            self::CDN . '/clip.mp4' => Http::response($clipBytes),
        ]);

        $this->artisan('ai:video-trial', [
            'track' => $track->slug,
            '--from' => 2,
            '--seconds' => 4,
            '--image' => $image,
            '--only' => ['wan-s2v', 'seedance-fast'],
            '--force' => true,
        ])
            ->expectsOutputToContain('Window: 2.0s to 6.0s.')
            ->assertSuccessful();

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/wan-video/wan-2.2-s2v/predictions')
            && $request['input']['audio'] === self::REPLICATE . '/files/f1'
            && $request['input']['image'] === self::REPLICATE . '/files/f1');
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/bytedance/seedance-1-pro-fast/predictions')
            && $request['input']['duration'] === 4
            && $request['input']['resolution'] === '720p'
            && ! isset($request['input']['audio']));

        $files = Storage::disk(config('filesystems.default'))
            ->allFiles(VideoTrial::DIRECTORY . "/{$track->id}");

        $this->assertCount(2, $files);

        $saved = $this->file('mp4', []);
        $content = Storage::disk(config('filesystems.default'))
            ->get($files[0]);

        file_put_contents($saved, $content);

        $probe = new Process(['ffmpeg', '-hide_banner', '-i', $saved]);
        $probe->run();

        $this->assertStringContainsString('Video: h264', $probe->getErrorOutput());
        $this->assertStringContainsString('Audio: aac', $probe->getErrorOutput());
    }

    public function test_b_roll_only_runs_skip_the_vocal_split(): void
    {
        $track = $this->track();
        $image = $this->file('png', ['-f', 'lavfi', '-i', 'color=c=blue:s=800x800', '-frames:v', '1']);
        $clip = $this->file('mp4', ['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=24:duration=3', '-pix_fmt', 'yuv420p']);
        $clipBytes = (string) file_get_contents($clip);

        Http::fake([
            self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/f1']], 201),
            self::REPLICATE . '/models/*/predictions' => Http::response([
                'id' => 'clip',
                'status' => 'succeeded',
                'output' => self::CDN . '/clip.mp4',
            ], 201),
            self::CDN . '/clip.mp4' => Http::response($clipBytes),
        ]);

        $this->artisan('ai:video-trial', [
            'track' => (string) $track->id,
            '--image' => $image,
            '--only' => ['p-video'],
            '--force' => true,
        ])
            ->assertSuccessful();

        Http::assertNotSent(fn(Request $request) => str_ends_with($request->url(), '/v1/predictions'));
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/prunaai/p-video/predictions')
            && $request['input']['save_audio'] === false);
    }

    public function test_the_dubbing_model_lip_syncs_a_clip_rendered_first(): void
    {
        $track = $this->track();
        $image = $this->file('png', ['-f', 'lavfi', '-i', 'color=c=green:s=1024x1024', '-frames:v', '1']);
        $clip = $this->file('mp4', ['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=24:duration=3', '-pix_fmt', 'yuv420p']);
        $vocal = $this->file('wav', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(440*2*PI*t):s=44100:d=4']);
        $vocalBytes = (string) file_get_contents($vocal);
        $clipBytes = (string) file_get_contents($clip);

        Http::fake([
            self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/f1']], 201),
            self::REPLICATE . '/predictions' => Http::response([
                'id' => 'split',
                'status' => 'succeeded',
                'output' => ['vocals' => self::CDN . '/vocals.wav'],
            ], 201),
            self::REPLICATE . '/models/prunaai/p-video/predictions' => Http::response([
                'id' => 'source',
                'status' => 'succeeded',
                'output' => self::CDN . '/source.mp4',
            ], 201),
            self::REPLICATE . '/models/*/predictions' => Http::response([
                'id' => 'clip',
                'status' => 'succeeded',
                'output' => self::CDN . '/clip.mp4',
            ], 201),
            self::CDN . '/vocals.wav' => Http::response($vocalBytes),
            self::CDN . '/clip.mp4' => Http::response($clipBytes),
        ]);

        $this->artisan('ai:video-trial', [
            'track' => $track->slug,
            '--seconds' => 4,
            '--image' => $image,
            '--only' => ['lipsync-2-pro'],
            '--force' => true,
        ])
            ->assertSuccessful();

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/sync/lipsync-2-pro/predictions')
            && $request['input']['video'] === self::CDN . '/source.mp4'
            && $request['input']['audio'] === self::REPLICATE . '/files/f1');
        Http::assertNotSent(fn(Request $request) => $request->url() === self::CDN . '/source.mp4');

        $files = Storage::disk(config('filesystems.default'))
            ->allFiles(VideoTrial::DIRECTORY . "/{$track->id}");

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('/lipsync-2-pro.mp4', $files[0]);
    }

    public function test_the_advanced_tier_runs_only_its_own_models(): void
    {
        Http::fake();

        $this->artisan('ai:video-trial', ['track' => $this->track()->slug, '--advanced' => true, '--seconds' => 5])
            ->expectsTable(['Model', 'Kind', 'Seconds', 'About (USD)'], [
                ['bytedance/omni-human-1.5', 'lip sync', 5, '0.800'],
                ['kwaivgi/kling-avatar-v2', 'lip sync', 5, '0.280'],
                ['veed/fabric-1.0', 'lip sync', 5, '0.750'],
                ['sync/lipsync-2-pro', 'lip sync over a p-video clip', 5, '0.516'],
                ['kwaivgi/kling-v3-video', 'b-roll', 5, '0.840'],
                ['google/veo-3.1-fast', 'b-roll', 6, '0.600'],
                ['bytedance/seedance-2.0', 'b-roll', 5, '0.900'],
            ])
            ->expectsConfirmation('This spends about $4.69 on Replicate. Go ahead?', 'no')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_unknown_models_are_refused_before_anything_runs(): void
    {
        Http::fake();

        $this->artisan('ai:video-trial', ['track' => $this->track()->slug, '--only' => ['sora']])
            ->expectsOutputToContain('Models must be among')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_costs_follow_each_model_s_pricing(): void
    {
        $this->assertSame(0.16, VideoCandidate::WAN_S2V->cost(8));
        $this->assertSame(0.2, VideoCandidate::SEEDANCE_FAST->cost(8));
        $this->assertSame(12.0, VideoCandidate::SEEDANCE_FAST->length(20));
        $this->assertSame(0.11, VideoCandidate::WAN_I2V_FAST->cost(8));
        $this->assertTrue(VideoCandidate::OMNI_HUMAN->sings());
        $this->assertFalse(VideoCandidate::P_VIDEO->sings());
        $this->assertSame(8.0, VideoCandidate::VEO_FAST->length(10));
        $this->assertSame(4.0, VideoCandidate::SEEDANCE_2->length(2));
        $this->assertSame(0.08, VideoCandidate::P_VIDEO->cost(4));
        $this->assertSame(0.413, VideoCandidate::LIPSYNC_2_PRO->cost(4));
        $this->assertNotContains('kling-v3', array_keys(VideoCandidate::brollOptions()));
    }

    /**
     * A released track with 10 seconds of audio.
     */
    protected function track(): Track
    {
        $owner = User::factory()
            ->create();
        $release = Release::query()
            ->forceCreate([
                'user_id' => $owner->id,
                'title' => 'Album',
                'slug' => 'album-' . uniqid(),
                'draft' => false,
            ]);

        $media = new Media;
        $name = 'trial-' . uniqid() . '.mp3';
        $audio = $this->file('mp3', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(220*2*PI*t):s=44100:d=10']);
        $path = $media->mediaInfo('music_tracks', $name)['path'];
        $bytes = (string) file_get_contents($audio);

        $media->disk->put($path, $bytes);

        $track = new Track;
        $track->forceFill([
            'release_id' => $release->id,
            'title' => 'Song',
            'slug' => 'song-' . uniqid(),
            'audio' => $name,
        ]);
        $track->saveQuietly();

        return $track;
    }

    /**
     * A temporary file made by FFmpeg from these input arguments, or an
     * empty path to write to when there are none.
     *
     * @param  list<string>  $args
     */
    protected function file(string $ext, array $args): string
    {
        $base = tempnam(sys_get_temp_dir(), 'trial-');
        @unlink($base);
        $path = "{$base}.{$ext}";
        $this->temp[] = $path;

        if ($args) {
            (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$args, $path]))
                ->mustRun();
        }

        return $path;
    }
}
