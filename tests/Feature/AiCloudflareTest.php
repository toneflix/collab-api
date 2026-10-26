<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Lyric;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Ai\Music\ElevenLabsMusicV2;
use App\Services\Ai\Music\MiniMaxMusic26;
use App\Services\Composer\Ai\CloudflareLyricModel;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Composer\Ai\ReplicateLyricModel;
use App\Services\Lyrics\Lyrics;
use App\Services\Moderation\Ai\CloudflareModeratorModel;
use App\Services\Moderation\Ai\ModeratorModel;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiCloudflareTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://cloudflare.test/client/v4/accounts/acc1';

    protected const CDN = 'https://cdn.cloudflare.test';

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    /** @var list<string> */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => null,
            'ai.providers.cloudflare.account' => 'acc1',
            'ai.providers.cloudflare.key' => 'cf_test',
            'ai.providers.cloudflare.url' => 'https://cloudflare.test/client/v4',
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_music_model' => ElevenLabsMusicV2::ID]);

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

        $this->project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Workers AI test', 'privacy' => 'private']);
        $this->track = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Idea', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();
        array_map(fn($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    protected function url(string $path = 'ai/music'): string
    {
        return "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/{$path}";
    }

    /**
     * A tone of this many seconds, as MP3.
     */
    protected function tone(float $seconds): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.mp3';
        exec(sprintf('ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*440*t):d=%s:s=44100" %s', $seconds, escapeshellarg($path)));

        return $path;
    }

    /**
     * Workers AI answering a partner music model with a link to a song.
     */
    protected function fakeMusic(string $file = 'song.mp3'): void
    {
        Http::fake([
            self::API . '/ai/run' => Http::response(['state' => 'Completed', 'result' => ['audio' => self::CDN . "/{$file}"]]),
            self::CDN . "/{$file}" => Http::response(file_get_contents($this->tone(4)), 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    protected function admin(): User
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');

        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');

        return $admin;
    }

    public function test_elevenlabs_music_v2_runs_on_workers_ai_without_replicate(): void
    {
        $this->fakeMusic();
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url(), ['prompt' => 'Warm highlife with horns', 'seconds' => 30, 'instrumental' => true])
            ->assertCreated()
            ->assertJsonPath('data.credits', 15)
            ->assertJsonPath('data.model', 'ElevenLabs Music v2')
            ->json('data.id');

        $this->assertSame(AiJob::SUCCEEDED, AiJob::findOrFail($id)->status);
        $this->assertSame(85, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/ai/run'
            && $request->hasHeader('Authorization', 'Bearer cf_test')
            && $request['model'] === 'elevenlabs/music-v2'
            && $request['input'] === [
                'prompt' => 'Warm highlife with horns',
                'music_length_ms' => 30000,
                'force_instrumental' => true,
                'output_format' => 'mp3_44100_192',
            ]);

        $stem = $this->track
            ->stems()
            ->sole();
        $this->assertSame('elevenlabs/music-v2', $stem->meta['ai']['model']);
    }

    public function test_minimax_music_26_writes_lyrics_when_none_are_given(): void
    {
        AiSettings::update(['ai_music_model' => MiniMaxMusic26::ID, 'ai_music_minimax_26_credits' => 30]);
        $this->fakeMusic('song.wav');
        Sanctum::actingAs($this->owner);

        $this->postJson($this->url(), ['prompt' => 'Afrobeats love song'])
            ->assertCreated()
            ->assertJsonPath('data.credits', 30);

        $this->postJson($this->url(), ['prompt' => 'Afrobeats love song', 'lyrics' => "[verse]\nSun on my face"])
            ->assertCreated();

        Http::assertSent(fn(Request $request) => ($request->data()['model'] ?? null) === 'minimax/music-2.6'
            && $request['input'] === ['prompt' => 'Afrobeats love song', 'lyrics_optimizer' => true, 'is_instrumental' => false, 'format' => 'wav']);
        Http::assertSent(fn(Request $request) => ($request->data()['model'] ?? null) === 'minimax/music-2.6'
            && $request['input'] === ['prompt' => 'Afrobeats love song', 'lyrics' => "[verse]\nSun on my face", 'lyrics_optimizer' => false, 'is_instrumental' => false, 'format' => 'wav']);

        $this->assertContains(MiniMaxMusic26::ID, array_map(fn($model) => $model::ID, AiSettings::lyricMusicModels()));
    }

    public function test_a_failed_run_refunds_the_credits_with_cloudflares_reason(): void
    {
        Http::fake([
            self::API . '/ai/run' => Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'No route for that model']]], 400),
        ]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url(), ['prompt' => 'Lofi beat', 'seconds' => 10])
            ->assertCreated()
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::FAILED, $job->status);
        $this->assertStringContainsString('No route for that model', $job->failure);
        $this->assertSame(100, Credits::for($this->owner)->total());
    }

    public function test_stems_are_transcribed_with_whisper_on_workers_ai(): void
    {
        AiSettings::update(['ai_lyrics_engine' => 'cloudflare']);
        Http::fake([
            self::API . '/ai/run/@cf/openai/whisper-large-v3-turbo' => Http::response(['success' => true, 'result' => [
                'text' => 'Sun is rising over Lagos',
                'transcription_info' => ['language' => 'en'],
                'segments' => [
                    ['start' => 1, 'end' => 3.5, 'text' => ' Sun is rising over Lagos', 'no_speech_prob' => 0.01, 'avg_logprob' => -0.2],
                    ['start' => 3.5, 'end' => 4, 'text' => ' ...', 'no_speech_prob' => 0.9, 'avg_logprob' => -1.5],
                ],
            ]]),
        ]);

        $stem = app(StemFiles::class)
            ->addStem($this->track, $this->owner, $this->tone(4), 'Lead vocals', ['start' => 10]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url("stems/{$stem->id}/lyrics"))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(AiJob::SUCCEEDED, AiJob::findOrFail($id)->status);

        $sheet = app(Lyrics::class)->for($this->track);
        $this->assertSame(Lyric::TRANSCRIBED, $sheet->source);
        $this->assertSame('en', $sheet->language);
        $this->assertSame([['start' => 11, 'end' => 13.5, 'text' => 'Sun is rising over Lagos']], $sheet->lines);

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/ai/run/@cf/openai/whisper-large-v3-turbo')
            && base64_decode($request['audio'], true) !== false
            && $request['task'] === 'transcribe');
    }

    public function test_composer_and_the_moderator_follow_their_engine_setting(): void
    {
        $this->assertInstanceOf(ReplicateLyricModel::class, app(LyricModel::class));

        AiSettings::update(['ai_composer_engine' => 'cloudflare', 'ai_moderator_engine' => 'cloudflare']);
        Http::fake([
            self::API . '/ai/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => '{"options": []}']]]),
            self::API . '/ai/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'approve']]]]),
        ]);

        $writer = app(LyricModel::class);
        $this->assertInstanceOf(CloudflareLyricModel::class, $writer);
        $this->assertTrue($writer->available());
        $this->assertSame('{"options": []}', $writer->ask('Write lyrics.', 'Next line'));

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/ai/v1/messages'
            && $request['model'] === 'anthropic/claude-haiku-4.5'
            && $request['system'] === 'Write lyrics.'
            && $request['messages'] === [['role' => 'user', 'content' => 'Next line']]);

        config(['ai.providers.cloudflare.models.moderator.default' => '@cf/meta/llama-4-scout-17b-16e-instruct']);
        $moderator = app(ModeratorModel::class);
        $this->assertInstanceOf(CloudflareModeratorModel::class, $moderator);
        $this->assertSame('approve', $moderator->ask('Moderate.', 'A release', 'https://cdn.test/cover.jpg'));

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/ai/v1/chat/completions'
            && $request['messages'][0] === ['role' => 'system', 'content' => 'Moderate.']
            && $request['messages'][1]['content'][0] === ['type' => 'image_url', 'image_url' => ['url' => 'https://cdn.test/cover.jpg']]);
    }

    public function test_admins_pick_workers_ai_only_for_tools_it_can_run(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/admin/ai', ['ai_stems_engine' => 'cloudflare'])
            ->assertJsonValidationErrors('ai_stems_engine');
        $this->putJson('/api/admin/ai', ['ai_composer_cloudflare_model' => 'not a model'])
            ->assertJsonValidationErrors('ai_composer_cloudflare_model');

        $this->putJson('/api/admin/ai', [
            'ai_lyrics_engine' => 'cloudflare',
            'ai_composer_engine' => 'cloudflare',
            'ai_moderator_engine' => 'cloudflare',
            'ai_composer_cloudflare_model' => '@cf/openai/gpt-oss-120b',
        ])
            ->assertAccepted()
            ->assertJsonPath('message', 'Saved. Set Replicate API Token (REPLICATE_API_TOKEN) in .env for the tools that use them.')
            ->assertJsonPath('data.client.engines.cloudflare', true)
            ->assertJsonPath('data.options.engines.cloudflare', 'Cloudflare Workers AI')
            ->assertJsonPath('data.options.toolEngines.ai_lyrics_engine', ['replicate', 'cloudflare'])
            ->assertJsonPath('data.options.toolEngines.ai_stems_engine', ['replicate', 'suno'])
            ->assertJsonPath('data.keys.cloudflare.0.env', 'CLOUDFLARE_ACCOUNT_ID');

        $this->assertSame('@cf/openai/gpt-oss-120b', AiSettings::composerCloudflareModel());
        $this->assertSame('cloudflare', AiSettings::lyricsEngine()->value);

        config(['ai.providers.cloudflare.key' => null]);
        $this->getJson('/api/admin/ai')
            ->assertJsonPath('data.client.engines.cloudflare', false);
    }
}
