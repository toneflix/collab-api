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
use App\Services\Ai\Music\MiniMaxMusic15;
use App\Services\Ai\Music\MiniMaxMusic25;
use App\Services\Ai\Music\StableAudio25;
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

class AiMusicModelsTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://replicate.test/v1';

    protected const OUTPUT = 'https://replicate.delivery/out/song';

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    /** @var list<string> */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        config(['ai.providers.replicate.key' => 'r8_test', 'ai.providers.replicate.url' => self::API]);

        AiSettings::update(['ai_enabled' => true, 'ai_stems_credits' => 5]);

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
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Models test', 'privacy' => 'private']);
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

    protected function url(): string
    {
        return "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/ai/music";
    }

    /**
     * A tone of this many seconds, as WAV.
     */
    protected function tone(float $seconds): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.wav';
        exec(sprintf('ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*440*t):d=%s:s=44100" %s', $seconds, escapeshellarg($path)));

        return $path;
    }

    /**
     * Replicate answering for an official model with a WAV song.
     */
    protected function fakeModel(string $model): void
    {
        Http::fake([
            self::API . '/files' => Http::response(['urls' => ['get' => self::API . '/files/ref']]),
            self::API . "/models/{$model}/predictions" => Http::response(['id' => 'p1', 'status' => 'succeeded', 'output' => self::OUTPUT], 201),
            self::OUTPUT => Http::response(file_get_contents($this->tone(4)), 200, ['Content-Type' => 'audio/wav']),
        ]);
    }

    protected function useModel(string $model): void
    {
        AiSettings::update(['ai_music_model' => $model]);
    }

    public function test_admins_switch_the_music_model_and_price_each_one(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');
        Sanctum::actingAs($admin);

        $this
            ->putJson('/api/admin/ai', ['ai_music_model' => 'meta/musicgen'])
            ->assertJsonValidationErrors('ai_music_model');

        $this->putJson('/api/admin/ai', ['ai_music_model' => MiniMaxMusic25::ID, 'ai_music_minimax_25_credits' => 24])
            ->assertAccepted()
            ->assertJsonPath('data.client.music.id', MiniMaxMusic25::ID)
            ->assertJsonPath('data.client.music.credits', 24)
            ->assertJsonPath('data.client.music.pricing', 'song')
            ->assertJsonPath('data.client.music.lyricsLength', [1, 3500])
            ->assertJsonCount(7, 'data.models');

        Sanctum::actingAs($this->owner);
        $this
            ->getJson('/api/account/ai')
            ->assertJsonPath('data.music.name', 'MiniMax Music 2.5');
    }

    public function test_minimax_music_25_sings_lyrics_and_costs_one_price_per_song(): void
    {
        $this->useModel(MiniMaxMusic25::ID);
        $this->fakeModel(MiniMaxMusic25::ID);
        Sanctum::actingAs($this->owner);

        $this
            ->postJson($this->url(), ['prompt' => 'Afrobeats love song'])
            ->assertJsonValidationErrors('lyrics');

        $id = $this->postJson($this->url(), ['prompt' => 'Afrobeats love song', 'lyrics' => "[verse]\nSun on my face", 'seconds' => 120])
            ->assertCreated()
            ->assertJsonPath('data.credits', 30)
            ->assertJsonPath('data.model', 'MiniMax Music 2.5')
            ->json('data.id');

        $this->assertSame(AiJob::SUCCEEDED, AiJob::findOrFail($id)->status);
        $this->assertSame(70, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/models/minimax/music-2.5/predictions'
            && $request['input'] === ['prompt' => 'Afrobeats love song', 'lyrics' => "[verse]\nSun on my face", 'audio_format' => 'wav', 'sample_rate' => 44100]);

        // The WAV it returns is stored once as a 320 kbps MP3, with its real length.
        $stem = $this->track
            ->stems()
            ->sole();
        $this->assertStringEndsWith('.mp3', $stem->src);
        $this->assertEqualsWithDelta(4, $stem->meta['duration'], 0.1);
    }

    public function test_minimax_music_15_follows_a_reference_from_the_track_or_an_upload(): void
    {
        $this->useModel(MiniMaxMusic15::ID);
        $this->fakeModel(MiniMaxMusic15::ID);
        Sanctum::actingAs($this->owner);

        $form = ['prompt' => 'Warm highlife with horns', 'lyrics' => 'Dance with me tonight'];
        $short = app(StemFiles::class)
            ->addStem($this->track, $this->owner, $this->tone(5), 'Short', [], 'wav');
        $this
            ->postJson($this->url(), [...$form, 'reference_stem_id' => $short->id])
            ->assertJsonValidationErrors('reference');

        $voice = app(StemFiles::class)
            ->addStem($this->track, $this->owner, $this->tone(16), 'Voice', [], 'wav');
        $this->postJson($this->url(), [...$form, 'reference_stem_id' => $voice->id, 'reference_kind' => 'voice'])
            ->assertCreated()
            ->assertJsonPath('data.credits', 10);

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/models/minimax/music-1.5/predictions')
            && $request['input'] === ['prompt' => 'Warm highlife with horns', 'lyrics' => 'Dance with me tonight', 'sample_rate' => 44100, 'bitrate' => 256000, 'voice_file' => self::API . '/files/ref']);

        $upload = new UploadedFile($this->tone(20), 'beat.wav', 'audio/wav', null, true);
        $id = $this->post($this->url(), [...$form, 'reference' => $upload, 'reference_kind' => 'instrumental'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->assertArrayHasKey('reference_file', AiJob::findOrFail($id)->input);
        Storage::disk(config('filesystems.default'))
            ->assertMissing(AiJob::findOrFail($id)->input['reference_file']);
        Http::assertSent(fn(Request $request) => isset($request['input']['instrumental_file']));
    }

    public function test_stable_audio_makes_instrumentals_of_a_set_length(): void
    {
        $this->useModel(StableAudio25::ID);
        $this->fakeModel(StableAudio25::ID);
        Sanctum::actingAs($this->owner);

        $this
            ->postJson($this->url(), ['prompt' => 'Ambient pads', 'seconds' => 200])
            ->assertJsonValidationErrors('seconds');

        $upload = new UploadedFile($this->tone(20), 'ref.wav', 'audio/wav', null, true);
        $this->post($this->url(), ['prompt' => 'Ambient pads', 'seconds' => 60, 'reference' => $upload], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('reference');

        $this->postJson($this->url(), ['prompt' => 'Ambient pads', 'seconds' => 60, 'seed' => 3])
            ->assertCreated()
            ->assertJsonPath('data.credits', 40);

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/models/stability-ai/stable-audio-2.5/predictions')
            && $request['input'] === ['prompt' => 'Ambient pads', 'duration' => 60, 'steps' => 8, 'seed' => 3]);
    }

    public function test_a_job_keeps_the_model_and_price_it_started_with(): void
    {
        $this->useModel(StableAudio25::ID);
        $this->fakeModel(StableAudio25::ID);
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url(), ['prompt' => 'Ambient pads', 'seconds' => 30])
            ->json('data.id');
        $this->useModel(MiniMaxMusic25::ID);

        $job = AiJob::findOrFail($id);
        $this->assertSame(StableAudio25::ID, $job->input['model']);
        $this->assertSame(40, $job->task()->credits($job->input));
    }
}
