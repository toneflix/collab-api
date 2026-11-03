<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Credits;
use App\Services\Audio\StemAudio;
use App\Services\Studio\MixPreview;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AiAddVocalsTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://suno.test';

    protected const UPLOADS = 'https://uploads.suno.test';

    protected const CDN = 'https://cdn.suno.test';

    protected User $owner;

    protected ProjectTrack $track;

    /** @var list<string> */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        config([
            'ai.providers.suno.key' => 'suno_test',
            'ai.providers.suno.url' => self::API,
            'ai.providers.suno.upload_url' => self::UPLOADS,
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_vocals_credits' => 22, 'ai_suno_model' => 'V5_5']);

        $plan = Plan::create([
            'slug' => 'ai-' . uniqid(), 'name' => 'AI', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 100],
        ]);
        $this->owner = User::factory()
            ->create();
        $this->owner
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);
        $this->owner = $this->owner->fresh();

        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Vocals test', 'privacy' => 'private']);
        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Beat', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();
        array_map(fn(string $file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    /**
     * A sine tone of this many seconds, as MP3.
     */
    protected function tone(float $seconds, int $frequency): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.mp3';
        exec(sprintf(
            'ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*%d*t):d=%s:s=44100" -ac 2 %s',
            $frequency,
            $seconds,
            escapeshellarg($path),
        ));

        return $path;
    }

    public function test_vocals_are_sung_over_the_mix_and_only_the_vocals_go_on_the_track(): void
    {
        $beat = $this->tone(12, 220);
        app(MixPreview::class)
            ->upload($this->track, $beat);
        $vocals = (string) file_get_contents($this->tone(12, 660));

        Http::fake([
            self::UPLOADS . '/api/file-stream-upload' => Http::response(['code' => 200, 'data' => ['fileUrl' => self::UPLOADS . '/files/beat.mp3']]),
            self::API . '/api/v1/generate/add-vocals' => Http::response(['code' => 200, 'data' => ['taskId' => 'sing-1']]),
            self::API . '/api/v1/generate/record-info*' => Http::response(['code' => 200, 'data' => [
                'taskId' => 'sing-1',
                'status' => 'SUCCESS',
                'response' => ['sunoData' => [['id' => 'audio-1', 'audioUrl' => self::CDN . '/song.mp3', 'duration' => 12]]],
            ]]),
            self::API . '/api/v1/vocal-removal/generate' => Http::response(['code' => 200, 'data' => ['taskId' => 'split-1']]),
            self::API . '/api/v1/vocal-removal/record-info*' => Http::response(['code' => 200, 'data' => [
                'successFlag' => 'SUCCESS',
                'response' => ['originUrl' => self::CDN . '/song.mp3', 'instrumentalUrl' => self::CDN . '/beat.mp3', 'vocalUrl' => self::CDN . '/vocals.mp3'],
            ]]),
            self::CDN . '/*' => Http::response($vocals, 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $job = app(AiStudio::class)
            ->start($this->owner, $this->track, 'vocals', [
                'title' => 'Lagos Lights',
                'prompt' => 'Afrobeats, warm female vocals',
                'lyrics' => "[Chorus]\nHold me close tonight",
                'vocal_gender' => 'f',
            ]);
        $job->refresh();

        $this->assertSame(AiJob::SUCCEEDED, $job->status, (string) $job->failure);
        $this->assertSame(78, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/generate/add-vocals'
            && $request['uploadUrl'] === self::UPLOADS . '/files/beat.mp3'
            && $request['model'] === 'V5_5'
            && $request['title'] === 'Lagos Lights'
            && $request['style'] === 'Afrobeats, warm female vocals'
            && $request['lyrics'] === "[Chorus]\nHold me close tonight"
            && $request['vocalGender'] === 'f'
            && filled($request['negativeTags']));
        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/vocal-removal/generate'
            && $request['taskId'] === 'sing-1'
            && $request['audioId'] === 'audio-1'
            && $request['type'] === 'separate_vocal');

        $stem = ProjectTrackStem::findOrFail($job->output['stems'][0]);
        $this->assertSame('Vocals', $stem->title);
        $this->assertSame(0, (int) $stem->meta['start']);
        $this->assertTrue($stem->meta['ai']['vocals']);
        $this->assertSame(1, $this->track->stems()->count());

        $audio = $this->files[] = app(StemFiles::class)
            ->local($stem);
        $this->assertEqualsWithDelta(12, app(StemAudio::class)->probe($audio)['duration'], 0.2);
    }

    public function test_a_track_with_no_mix_gets_its_credits_back(): void
    {
        Http::fake();

        $job = app(AiStudio::class)
            ->start($this->owner, $this->track, 'vocals', ['title' => 'Nothing yet', 'lyrics' => 'La la']);
        $job->refresh();

        $this->assertSame(AiJob::FAILED, $job->status);
        $this->assertSame(100, Credits::for($this->owner)->total());
        Http::assertNothingSent();
    }
}
