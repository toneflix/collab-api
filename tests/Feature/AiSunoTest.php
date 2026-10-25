<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Ai\Music\SunoMusic;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiSunoTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://suno.test';

    protected const UPLOADS = 'https://uploads.suno.test';

    protected const CDN = 'https://cdn.suno.test';

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => 'https://replicate.test/v1',
            'ai.providers.suno.key' => 'suno_test',
            'ai.providers.suno.url' => self::API,
            'ai.providers.suno.upload_url' => self::UPLOADS,
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_music_model' => SunoMusic::ID, 'ai_music_suno_credits' => 12]);

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
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Suno test', 'privacy' => 'private']);
        $this->track = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Idea', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();

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
        $path = tempnam(sys_get_temp_dir(), 'tone-') . '.mp3';
        exec(sprintf('ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*440*t):d=%s:s=44100" %s', $seconds, escapeshellarg($path)));

        return $path;
    }

    protected function mp3(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/audio.mp3'));
    }

    /**
     * Suno answering a song request with two versions after one look at a
     * pending task, and an upload host for reference audio.
     *
     * @param  array<int, mixed>  $extra  More fakes, matched first
     */
    protected function fakeSuno(array $extra = []): void
    {
        $songs = [
            ['id' => 'audio-1', 'audioUrl' => self::CDN . '/one.mp3', 'title' => 'Sunset Groove', 'duration' => 12.5, 'imageUrl' => self::CDN . '/one.jpg', 'tags' => 'afrobeats'],
            ['id' => 'audio-2', 'audio_url' => self::CDN . '/two.mp3', 'title' => 'Sunset Groove', 'duration' => 13.25],
        ];

        Http::fake([
            ...$extra,
            self::API . '/api/v1/generate' => Http::response(['code' => 200, 'msg' => 'success', 'data' => ['taskId' => 'task-1']]),
            self::API . '/api/v1/generate/record-info*' => Http::sequence()
                ->push(['code' => 200, 'data' => ['taskId' => 'task-1', 'status' => 'PENDING']])
                ->push(['code' => 200, 'data' => ['taskId' => 'task-1', 'status' => 'SUCCESS', 'response' => ['sunoData' => $songs]]]),
            self::UPLOADS . '/api/file-stream-upload' => Http::response(['success' => true, 'code' => 200, 'data' => ['fileUrl' => self::UPLOADS . '/files/ref.mp3']]),
            self::CDN . '/*' => Http::response($this->mp3(), 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    /**
     * Suno's stem split answering with these parts.
     *
     * @return array<string, mixed>
     */
    protected function splitFakes(): array
    {
        return [
            self::API . '/api/v1/vocal-removal/generate' => Http::response(['code' => 200, 'data' => ['taskId' => 'split-1']]),
            self::API . '/api/v1/vocal-removal/record-info*' => Http::response(['code' => 200, 'data' => [
                'taskId' => 'split-1',
                'successFlag' => 'SUCCESS',
                'response' => [
                    'originUrl' => self::CDN . '/origin.mp3',
                    'vocalUrl' => self::CDN . '/vocal.mp3',
                    'backingVocalsUrl' => self::CDN . '/backing.mp3',
                    'drumsUrl' => self::CDN . '/drums.mp3',
                    'bassUrl' => null,
                ],
            ]]),
        ];
    }

    public function test_admins_pick_suno_its_version_and_how_stems_are_split(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');
        Sanctum::actingAs($admin);

        $this
            ->putJson('/api/admin/ai', ['ai_suno_model' => 'V2', 'ai_stems_engine' => 'demucs', 'ai_stems_suno_type' => 'split_everything'])
            ->assertJsonValidationErrors(['ai_suno_model', 'ai_stems_engine', 'ai_stems_suno_type']);

        $this
            ->putJson('/api/admin/ai', ['ai_suno_model' => 'V6', 'ai_suno_keep_both' => true, 'ai_stems_engine' => 'suno', 'ai_stems_suno_type' => 'separate_vocal'])
            ->assertAccepted()
            ->assertJsonPath('message', 'AI settings saved.')
            ->assertJsonPath('data.client.music.name', 'Suno v6')
            ->assertJsonPath('data.client.music.engine', 'suno')
            ->assertJsonPath('data.client.music.lyricsRequired', false)
            ->assertJsonPath('data.client.music.maxSeconds', 300)
            ->assertJsonPath('data.client.stemsEngine', 'suno')
            ->assertJsonPath('data.client.costs.stems', 50)
            ->assertJsonPath('data.client.engines', ['replicate' => true, 'suno' => true])
            ->assertJsonPath('data.keys.suno.0.env', 'SUNO_API_KEY')
            ->assertJsonPath('data.options.sunoVersions.V5_5', 'v5.5');

        config(['ai.providers.suno.key' => null]);
        $this
            ->putJson('/api/admin/ai', ['ai_suno_keep_both' => false])
            ->assertAccepted()
            ->assertJsonPath('message', 'Saved. Set Suno API Key (SUNO_API_KEY) in .env for the tools that use them.');
    }

    public function test_tools_on_an_engine_without_its_key_are_turned_away(): void
    {
        config(['ai.providers.suno.key' => null]);
        Sanctum::actingAs($this->owner);

        $this
            ->postJson($this->url(), ['prompt' => 'Afrobeats love song', 'seconds' => 30])
            ->assertJsonValidationErrors(['ai' => 'Suno isn\'t set up yet']);

        $this->assertSame(0, AiJob::query()->count());
        $this->assertSame(100, Credits::for($this->owner)->total());
    }

    public function test_suno_sings_lyrics_into_one_stem_when_only_the_first_version_is_kept(): void
    {
        $this->fakeSuno();
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url(), ['prompt' => 'Afrobeats love song', 'lyrics' => "[Verse]\nSun on my face", 'seconds' => 30])
            ->assertCreated()
            ->assertJsonPath('data.credits', 12)
            ->assertJsonPath('data.model', 'Suno v5.5')
            ->json('data.id');

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/generate'
            && $request->hasHeader('Authorization', 'Bearer suno_test')
            && $request->data() === [
                'customMode' => true,
                'instrumental' => false,
                'model' => 'V5_5',
                'style' => 'Afrobeats love song',
                'title' => 'Afrobeats love song',
                'prompt' => "[Verse]\nSun on my face",
                'duration' => 30,
                'callBackUrl' => route('ai.suno.callback'),
            ]);

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::SUCCEEDED, $job->status);
        $this->assertSame(88, Credits::for($this->owner)->total());

        $stem = $this->track
            ->stems()
            ->sole();
        $this->assertSame([$stem->id], $job->output['stems']);
        $this->assertSame('Sunset Groove', $stem->title);
        $this->assertSame(12.5, $stem->meta['duration']);
        $this->assertSame(['task' => 'task-1', 'audio' => 'audio-1'], $stem->meta['ai']['suno']);
        $this->assertSame('suno/V5_5', $stem->meta['ai']['model']);
        Http::assertNotSent(fn(Request $request) => $request->url() === self::CDN . '/two.mp3');
    }

    public function test_suno_writes_the_lyrics_and_follows_a_reference_song_when_none_are_given(): void
    {
        $this->fakeSuno();
        Sanctum::actingAs($this->owner);

        $short = new UploadedFile(base_path('tests/fixtures/audio.mp3'), 'ref.mp3', 'audio/mpeg', null, true);
        $this->post($this->url(), ['prompt' => 'Highlife about home', 'seconds' => 30, 'reference' => $short, 'reference_kind' => 'voice'], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('reference_kind');

        $tone = $this->tone(8);
        $reference = app(StemFiles::class)
            ->addStem($this->track, $this->owner, $tone, 'Reference');
        @unlink($tone);

        $this->postJson($this->url(), ['prompt' => 'Highlife about home', 'seconds' => 30, 'reference_stem_id' => $reference->id])
            ->assertCreated();

        Http::assertSent(fn(Request $request) => $request->url() === self::UPLOADS . '/api/file-stream-upload');
        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/generate'
            && $request->data() === [
                'customMode' => false,
                'instrumental' => false,
                'model' => 'V5_5',
                'prompt' => 'Highlife about home',
                'audioUrls' => [self::UPLOADS . '/files/ref.mp3'],
                'callBackUrl' => route('ai.suno.callback'),
            ]);
    }

    public function test_people_pick_which_of_both_versions_goes_on_the_track(): void
    {
        AiSettings::update(['ai_suno_keep_both' => true]);
        $this->fakeSuno();
        Sanctum::actingAs($this->owner);

        $response = $this
            ->postJson($this->url(), ['prompt' => 'Lofi study beat', 'instrumental' => true, 'seconds' => 20])
            ->assertCreated();
        $id = $response->json('data.id');

        $this->assertSame(0, $this->track->stems()->count());

        $job = $this
            ->getJson("/api/creator/ai/jobs/{$id}")
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonCount(2, 'data.versions')
            ->assertJsonPath('data.versions.0.title', 'Sunset Groove')
            ->assertJsonPath('data.versions.0.image', self::CDN . '/one.jpg')
            ->assertJsonPath('data.versions.1.duration', 13.25)
            ->assertJsonPath('data.chosen', null)
            ->json('data');

        // Each version plays from its signed link, and only from it.
        $this->get($job['versions'][1]['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
        $this
            ->get("/api/ai/jobs/{$id}/versions/1/audio")
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this
            ->postJson("/api/creator/ai/jobs/{$id}/versions/1")
            ->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this
            ->postJson("/api/creator/ai/jobs/{$id}/versions/5")
            ->assertJsonValidationErrors('version');
        $this
            ->postJson("/api/creator/ai/jobs/{$id}/versions/1")
            ->assertAccepted();

        $job = AiJob::findOrFail($id);
        $stem = $this->track
            ->stems()
            ->sole();
        $this->assertSame(1, $job->output['chosen']);
        $this->assertSame([$stem->id], $job->output['stems']);
        $this->assertSame(13.25, $stem->meta['duration']);
        $this->assertSame('audio-2', $stem->meta['ai']['suno']['audio']);
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles("ai/versions/{$id}"));

        $this
            ->postJson("/api/creator/ai/jobs/{$id}/versions/0")
            ->assertJsonValidationErrors('version');
        $this
            ->getJson("/api/creator/ai/jobs/{$id}")
            ->assertJsonPath('data.chosen', 1)
            ->assertJsonPath('data.versions.0.url', null);
    }

    public function test_a_picked_suno_song_is_split_by_suno_without_an_upload(): void
    {
        AiSettings::update(['ai_suno_keep_both' => true, 'ai_stems_engine' => 'suno', 'ai_stems_suno_credits' => 20]);
        $this->fakeSuno($this->splitFakes());
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url(), ['prompt' => 'Gospel choir', 'lyrics' => 'Glory', 'seconds' => 20, 'split' => true])
            ->assertCreated()
            ->assertJsonPath('data.credits', 32)
            ->json('data.id');

        $this
            ->postJson("/api/creator/ai/jobs/{$id}/versions/0")
            ->assertAccepted();

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/vocal-removal/generate'
            && $request['taskId'] === 'task-1'
            && $request['audioId'] === 'audio-1'
            && $request['type'] === 'split_stem'
            && ! isset($request['audioUrl']));
        Http::assertNotSent(fn(Request $request) => $request->url() === self::UPLOADS . '/api/file-stream-upload');

        $this->assertSame(
            ['Sunset Groove Backing Vocals', 'Sunset Groove Drums', 'Sunset Groove Vocals'],
            $this->track->stems()->orderBy('title')->pluck('title')->all()
        );

        $stem = $this->track
            ->stems()
            ->where('title', 'Sunset Groove Drums')
            ->sole();
        $this->assertSame('suno/split_stem', $stem->meta['ai']['model']);
        $this->assertArrayNotHasKey('suno', $stem->meta['ai']);
    }

    public function test_an_existing_stem_is_uploaded_and_split_on_suno(): void
    {
        AiSettings::update(['ai_stems_engine' => 'suno', 'ai_stems_suno_type' => 'separate_vocal', 'ai_stems_suno_credits' => 10]);
        $this->fakeSuno($this->splitFakes());
        $stem = app(StemFiles::class)
            ->addStem($this->track, $this->owner, base_path('tests/fixtures/audio.mp3'), 'Beat');
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url("stems/{$stem->id}/split"))
            ->assertCreated()
            ->assertJsonPath('data.credits', 10)
            ->json('data.id');

        $this->assertSame('suno', AiJob::findOrFail($id)->input['stems_engine']);
        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/vocal-removal/generate'
            && $request->data() === ['type' => 'separate_vocal', 'audioUrl' => self::UPLOADS . '/files/ref.mp3', 'callBackUrl' => route('ai.suno.callback')]);
        $this->assertSame(4, $this->track->stems()->count());
    }

    public function test_a_rejected_suno_task_fails_the_job_and_refunds_it(): void
    {
        Http::fake([
            self::API . '/api/v1/generate' => Http::response(['code' => 200, 'data' => ['taskId' => 'task-9']]),
            self::API . '/api/v1/generate/record-info*' => Http::response(['code' => 200, 'data' => [
                'taskId' => 'task-9', 'status' => 'SENSITIVE_WORD_ERROR', 'errorMessage' => 'Lyrics contain a blocked word',
            ]]),
        ]);
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url(), ['prompt' => 'Drill', 'lyrics' => 'Bad words', 'seconds' => 20])
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::FAILED, $job->status);
        $this->assertStringContainsString('SENSITIVE_WORD_ERROR. Lyrics contain a blocked word', $job->failure);
        $this->assertSame(100, Credits::for($this->owner)->total());
    }

    public function test_suno_errors_in_the_body_fail_the_job(): void
    {
        Http::fake([
            self::API . '/api/v1/generate' => Http::response(['code' => 429, 'msg' => 'Insufficient credits']),
        ]);
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url(), ['prompt' => 'Jazz', 'instrumental' => true, 'seconds' => 20])
            ->json('data.id');

        $this->assertStringContainsString('Suno answered 429: Insufficient credits', AiJob::findOrFail($id)->failure);
    }

    public function test_versions_nobody_picked_are_deleted_after_a_week(): void
    {
        AiSettings::update(['ai_suno_keep_both' => true]);
        $this->fakeSuno();
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url(), ['prompt' => 'Lofi', 'instrumental' => true, 'seconds' => 20])
            ->json('data.id');
        $disk = Storage::disk(config('filesystems.default'));
        $this->assertCount(2, $disk->allFiles("ai/versions/{$id}"));

        AiJob::whereKey($id)
            ->update(['finished_at' => now()->subDays(8)]);
        $this
            ->artisan('ai:sweep')
            ->assertSuccessful();

        $this->assertSame([], $disk->allFiles("ai/versions/{$id}"));
        $this->assertTrue(AiJob::findOrFail($id)->output['expired']);
        $this
            ->postJson("/api/creator/ai/jobs/{$id}/versions/0")
            ->assertJsonValidationErrors('version');
    }

    public function test_suno_callbacks_are_answered(): void
    {
        $this
            ->postJson('/api/ai/suno/callback', ['code' => 200, 'data' => ['callbackType' => 'complete']])
            ->assertOk();
    }
}
