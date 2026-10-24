<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiCreditEntry;
use App\Models\AiJob;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Ai\Audio;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AiMusicTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://replicate.test/v1';

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        config(['ai.providers.replicate.key' => 'r8_test', 'ai.providers.replicate.url' => self::API]);

        AiSettings::update(['ai_enabled' => true, 'ai_music_credits' => 5, 'ai_stems_credits' => 5]);

        $this->owner = $this->member(100);
        $this->project = Project::query()->forceCreate(['user_id' => $this->owner->id, 'title' => 'AI test', 'privacy' => 'private']);
        $this->track = $this->project->tracks()->forceCreate(['title' => 'Idea', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();

        parent::tearDown();
    }

    protected function member(int $credits): User
    {
        $plan = Plan::create([
            'slug' => 'ai-'.uniqid(), 'name' => 'AI', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => $credits],
        ]);

        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
        ]);

        return $user->fresh();
    }

    protected function url(string $path = 'ai/music'): string
    {
        return "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/{$path}";
    }

    /**
     * Replicate answering for Demucs, a community model that runs by version.
     */
    protected function fakeDemucs(): void
    {
        $stems = collect(['vocals', 'drums', 'bass', 'other'])->mapWithKeys(fn ($part) => [$part => "https://replicate.delivery/out/{$part}.mp3"]);

        Http::fake([
            self::API.'/files' => Http::response(['urls' => ['get' => self::API.'/files/f1']]),
            self::API.'/models/ryan5453/demucs/predictions' => Http::response(['detail' => 'version required'], 404),
            self::API.'/models/ryan5453/demucs' => Http::response(['latest_version' => ['id' => 'v-demucs']]),
            self::API.'/predictions' => Http::response(['id' => 'p-stems', 'status' => 'starting']),
            self::API.'/predictions/p-stems' => Http::response(['id' => 'p-stems', 'status' => 'succeeded', 'output' => $stems->all()]),
            'https://replicate.delivery/*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    public function test_music_is_generated_into_a_stem_with_the_laravel_ai_sdk(): void
    {
        Audio::fake([base64_encode('ID3-generated')]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url(), ['prompt' => 'Warm lofi beat with vinyl crackle', 'seconds' => 30, 'instrumental' => true, 'seed' => 7])
            ->assertCreated()
            ->assertJsonPath('data.credits', 15)
            ->json('data.id');

        Audio::assertGenerated(fn (AudioPrompt $prompt) => $prompt->text === 'Warm lofi beat with vinyl crackle'
            && $prompt->providerOptions === ['music_length_ms' => 30000, 'force_instrumental' => true, 'seed' => 7]);

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::SUCCEEDED, $job->status);

        $stem = $this->track->stems()->sole();
        $this->assertSame($stem->id, $job->output['stems'][0]);
        $this->assertSame('Warm lofi beat with vinyl crackle', $stem->meta['ai']['prompt']);
        Storage::disk(config('filesystems.default'))->assertExists('public/media/music/stem/'.$stem->src);
        $this->assertSame(85, Credits::for($this->owner)->total());
    }

    public function test_the_replicate_driver_runs_elevenlabs_music(): void
    {
        Http::fake([
            self::API.'/models/elevenlabs/music/predictions' => Http::response([
                'id' => 'p-music', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out/song.mp3',
            ]),
            'https://replicate.delivery/*' => Http::response('ID3-song', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $audio = Audio::of('Afrobeats groove')->withProviderOptions(['music_length_ms' => 10000])->generate('replicate');

        $this->assertSame('ID3-song', $audio->content());
        $this->assertSame('elevenlabs/music', $audio->meta->model);

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/models/elevenlabs/music/predictions'
            && $request['input'] === ['prompt' => 'Afrobeats groove', 'music_length_ms' => 10000]
            && $request->hasHeader('Authorization', 'Bearer r8_test')
            && $request->hasHeader('Prefer', 'wait=60'));
    }

    public function test_generated_music_can_be_split_into_stems_with_demucs(): void
    {
        Audio::fake([base64_encode('ID3-generated')]);
        $this->fakeDemucs();
        Sanctum::actingAs($this->owner);

        $this->postJson($this->url(), ['prompt' => 'Highlife guitar jam', 'seconds' => 20, 'split' => true])
            ->assertCreated()
            ->assertJsonPath('data.credits', 15);

        $this->assertSame(
            ['Highlife guitar jam Bass', 'Highlife guitar jam Drums', 'Highlife guitar jam Other', 'Highlife guitar jam Vocals'],
            $this->track->stems()->orderBy('title')->pluck('title')->all()
        );

        Http::assertSent(fn (Request $request) => $request->url() === self::API.'/predictions'
            && $request['version'] === 'v-demucs'
            && $request['input'] === ['audio' => self::API.'/files/f1', 'format' => 'mp3']);
    }

    public function test_an_existing_stem_can_be_split(): void
    {
        $this->fakeDemucs();
        $file = tempnam(sys_get_temp_dir(), 'test-');
        file_put_contents($file, 'ID3-mix');
        $stem = app(StemFiles::class)->addStem($this->track, $this->owner, $file, 'Full mix');
        @unlink($file);

        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("stems/{$stem->id}/split"))->assertCreated()->assertJsonPath('data.kind', 'stems');

        $this->assertSame(5, $this->track->stems()->count());
        $this->assertSame(95, Credits::for($this->owner)->total());
    }

    public function test_failed_jobs_refund_their_credits(): void
    {
        Audio::fake(fn () => throw new RuntimeException('Model is down'));
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url(), ['prompt' => 'Amapiano log drums', 'seconds' => 60])->assertCreated()->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::FAILED, $job->status);
        $this->assertStringContainsString('refunded', $job->error);
        $this->assertSame('RuntimeException: Model is down', $job->failure);
        $this->assertSame(100, Credits::for($this->owner)->total());
        $this->assertSame(['refund', 'spend'], AiCreditEntry::where('user_id', $this->owner->id)->orderBy('type')->pluck('type')->all());
        $this->assertSame(0, $this->track->stems()->count());
    }

    public function test_ai_tools_need_access_credits_and_a_track_you_can_edit(): void
    {
        Audio::fake();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url(), ['prompt' => 'Drill beat', 'seconds' => 30])->assertForbidden();

        $this->project->forceFill(['user_id' => $poor = $this->member(5)->id])->save();
        Sanctum::actingAs(User::find($poor));
        $this->postJson($this->url(), ['prompt' => 'Drill beat', 'seconds' => 30])->assertJsonValidationErrors('credits');

        $free = User::factory()->create();
        $this->project->forceFill(['user_id' => $free->id])->save();
        Sanctum::actingAs($free);
        $this->postJson($this->url(), ['prompt' => 'Drill beat', 'seconds' => 30])->assertJsonValidationErrors('ai');

        $this->project->forceFill(['user_id' => $this->owner->id])->save();
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url(), ['prompt' => 'Drill beat', 'seconds' => 301])->assertJsonValidationErrors('seconds');

        config(['ai.providers.replicate.key' => null]);
        $this->postJson($this->url(), ['prompt' => 'Drill beat', 'seconds' => 30])->assertJsonValidationErrors('ai');

        Audio::assertNothingGenerated();
    }

    public function test_a_track_lists_its_jobs_and_owners_can_check_one(): void
    {
        Audio::fake();
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url(), ['prompt' => 'Gospel choir', 'seconds' => 10])->json('data.id');

        $this->getJson($this->url('ai/jobs'))->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'succeeded');
        $this->getJson("/api/creator/ai/jobs/{$id}")->assertOk()->assertJsonPath('data.prompt', 'Gospel choir');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/creator/ai/jobs/{$id}")->assertNotFound();
    }

    public function test_jobs_that_stopped_are_refunded_by_the_sweep(): void
    {
        $job = AiJob::create(['user_id' => $this->owner->id, 'project_track_id' => $this->track->id, 'kind' => 'music', 'status' => AiJob::RUNNING, 'input' => ['prompt' => 'Jazz', 'seconds' => 10], 'credits' => 5]);
        Credits::for($this->owner)->spend(5, 'Music: Jazz', $job);
        AiJob::whereKey($job->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertSame(AiJob::FAILED, $job->fresh()->status);
        $this->assertSame(100, Credits::for($this->owner)->total());
    }
}
