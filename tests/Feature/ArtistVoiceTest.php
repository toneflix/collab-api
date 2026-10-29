<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\ArtistVoice;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Ai\Music\MiniMaxMusic15;
use App\Services\Ai\Music\SunoMusic;
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

class ArtistVoiceTest extends TestCase
{
    use DatabaseTransactions;

    protected const SUNO = 'https://suno.test';

    protected const UPLOADS = 'https://uploads.suno.test';

    protected const CDN = 'https://cdn.suno.test';

    protected const REPLICATE = 'https://replicate.test/v1';

    protected User $owner;

    protected Artist $artist;

    protected Project $project;

    protected ProjectTrack $track;

    /** @var list<string> */
    protected array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => self::REPLICATE,
            'ai.providers.suno.key' => 'suno_test',
            'ai.providers.suno.url' => self::SUNO,
            'ai.providers.suno.upload_url' => self::UPLOADS,
        ]);

        AiSettings::update([
            'ai_enabled' => true,
            'ai_music_model' => SunoMusic::ID,
            'ai_composer_music_model' => SunoMusic::ID,
            'ai_music_suno_credits' => 12,
            'ai_music_minimax_15_credits' => 20,
        ]);

        $plan = Plan::create([
            'slug' => 'voice-' . uniqid(), 'name' => 'Voice', 'price' => 0, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 100],
        ]);
        $this->owner = User::factory()
            ->create();
        $this->owner
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 0,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);
        $this->owner = $this->owner->fresh();

        $this->artist = $this->owner
            ->artists()
            ->create(['name' => 'Ada Lumen ' . uniqid()]);
        $this->project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Voice test', 'privacy' => 'private']);
        $this->track = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Idea', 'privacy' => 'private', 'artist_id' => $this->artist->id]);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();
        array_map(fn(string $path) => @unlink($path), $this->temp);

        parent::tearDown();
    }

    protected function voiceUrl(string $path = ''): string
    {
        return "/api/creator/artists/{$this->artist->id}/voice" . ($path !== '' ? "/{$path}" : '');
    }

    /**
     * A tone of this many seconds, as MP3.
     */
    protected function tone(float $seconds): string
    {
        $path = $this->temp[] = tempnam(sys_get_temp_dir(), 'tone-') . '.mp3';
        exec(sprintf('ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*440*t):d=%s:s=44100" %s', $seconds, escapeshellarg($path)));

        return $path;
    }

    protected function upload(float $seconds, string $name = 'voice.mp3'): UploadedFile
    {
        return new UploadedFile($this->tone($seconds), $name, 'audio/mpeg', null, true);
    }

    /**
     * Suno's voice check: a phrase after one pending look, and a voice built
     * from the recording of it.
     *
     * @param  array<string, mixed>  $extra  More fakes, replacing these by pattern
     */
    protected function fakeSunoVoice(array $extra = []): void
    {
        Http::fake([
            self::UPLOADS . '/api/file-stream-upload' => Http::response(['code' => 200, 'data' => ['fileUrl' => self::UPLOADS . '/files/voice.mp3']]),
            self::SUNO . '/api/v1/voice/validate' => Http::response(['code' => 200, 'data' => ['taskId' => 'check-1']]),
            self::SUNO . '/api/v1/voice/validate-info*' => Http::sequence()
                ->push(['code' => 200, 'data' => ['taskId' => 'check-1', 'status' => 'processing_validate']])
                ->push(['code' => 200, 'data' => ['taskId' => 'check-1', 'status' => 'wait_validating', 'validateInfo' => 'Sunlight on the river']])
                ->whenEmpty(Http::response(['code' => 200, 'data' => ['taskId' => 'check-1', 'status' => 'wait_validating', 'validateInfo' => 'Rain over the hills']])),
            self::SUNO . '/api/v1/voice/generate' => Http::response(['code' => 200, 'data' => ['taskId' => 'voice-task']]),
            self::SUNO . '/api/v1/voice/record-info*' => Http::response(['code' => 200, 'data' => ['taskId' => 'voice-task', 'status' => 'success', 'voiceId' => 'voice-abc']]),
            ...$extra,
        ]);
    }

    /**
     * Suno answering song requests with one version of this many seconds.
     *
     * @return array<string, mixed>
     */
    protected function sunoSongs(float $seconds = 20): array
    {
        return [
            self::SUNO . '/api/v1/generate' => Http::response(['code' => 200, 'data' => ['taskId' => 'song-task']]),
            self::SUNO . '/api/v1/generate/record-info*' => Http::response(['code' => 200, 'data' => ['taskId' => 'song-task', 'status' => 'SUCCESS', 'response' => ['sunoData' => [
                ['id' => 'audio-1', 'audioUrl' => self::CDN . '/one.mp3', 'title' => 'Voice', 'duration' => $seconds],
            ]]]]),
            self::CDN . '/*' => Http::response((string) file_get_contents($this->tone($seconds)), 200, ['Content-Type' => 'audio/mpeg']),
        ];
    }

    public function test_owners_upload_their_voice_and_record_the_phrase_to_build_it(): void
    {
        Sanctum::actingAs($this->owner);
        $this->fakeSunoVoice();

        $this->post($this->voiceUrl('sample'), ['sample' => $this->upload(8)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('sample');

        $this->post($this->voiceUrl('sample'), ['sample' => $this->upload(25)], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', ArtistVoice::READY)
            ->assertJsonPath('data.source', ArtistVoice::UPLOADED);

        $voice = $this->artist
            ->voice()
            ->firstOrFail();
        $this->assertEqualsWithDelta(25, $voice->seconds, 0.2);
        Storage::disk(config('filesystems.default'))
            ->assertExists($voice->file);

        // The phrase is ready to record.
        $this->getJson($this->voiceUrl())
            ->assertOk()
            ->assertJsonPath('data.check', ArtistVoice::CHECK_RECORDING)
            ->assertJsonPath('data.phrase', 'Sunlight on the river')
            ->assertJsonPath('data.built', false);
        Http::assertSent(fn(Request $request) => $request->url() === self::SUNO . '/api/v1/voice/validate'
            && $request['voiceUrl'] === self::UPLOADS . '/files/voice.mp3'
            && $request['vocalStartS'] === 0
            && $request['vocalEndS'] === 25);

        $this->post($this->voiceUrl('check'), ['recording' => $this->upload(4, 'phrase.webm')], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $voice->refresh();
        $this->assertSame('voice-abc', $voice->persona_id);
        $this->assertSame(ArtistVoice::VOICE_PERSONA, $voice->persona_kind);
        $this->assertSame(ArtistVoice::CHECK_DONE, $voice->check_status);
        Http::assertSent(fn(Request $request) => $request->url() === self::SUNO . '/api/v1/voice/generate'
            && $request['taskId'] === 'check-1'
            && $request['verifyUrl'] === self::UPLOADS . '/files/voice.mp3');

        $this->getJson('/api/creator/voices')
            ->assertOk()
            ->assertJsonPath('data.0.artistId', $this->artist->id)
            ->assertJsonPath('data.0.studio', true)
            ->assertJsonPath('data.0.composer', true);

        // Music sung in the voice.
        $this->fakeSunoVoice($this->sunoSongs(30));

        $this->postJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/ai/music", [
            'prompt' => 'Warm highlife',
            'seconds' => 30,
            'lyrics' => 'Dance with me tonight',
            'voice_artist_id' => $this->artist->id,
        ])->assertStatus(201);

        Http::assertSent(fn(Request $request) => $request->url() === self::SUNO . '/api/v1/generate'
            && $request['personaId'] === 'voice-abc'
            && $request['personaModel'] === 'voice_persona');
    }

    public function test_a_failed_phrase_recording_can_get_a_new_phrase(): void
    {
        Sanctum::actingAs($this->owner);
        $this->fakeSunoVoice([
            self::SUNO . '/api/v1/voice/record-info*' => Http::response(['code' => 200, 'data' => ['taskId' => 'voice-task', 'status' => 'processing_validate_fail', 'errorMessage' => 'mismatch']]),
        ]);

        $this->post($this->voiceUrl('sample'), ['sample' => $this->upload(22)], ['Accept' => 'application/json'])
            ->assertStatus(202);
        $this->post($this->voiceUrl('check'), ['recording' => $this->upload(4)], ['Accept' => 'application/json'])
            ->assertStatus(202);

        $this->getJson($this->voiceUrl())
            ->assertJsonPath('data.check', ArtistVoice::CHECK_FAILED)
            ->assertJsonPath('data.built', false)
            ->assertJsonPath('data.failure', 'Your recording didn\'t pass the voice check. Try again with a new phrase.');

        $this->post($this->voiceUrl('check'), ['recording' => $this->upload(4)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('recording');

        $this->postJson($this->voiceUrl('check/restart'))
            ->assertStatus(202);

        $this->getJson($this->voiceUrl())
            ->assertJsonPath('data.check', ArtistVoice::CHECK_RECORDING)
            ->assertJsonPath('data.phrase', 'Rain over the hills');
    }

    public function test_only_the_artists_account_manages_its_voice(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-content', 'web');

        $moderator = User::factory()
            ->create();
        $moderator->givePermissionTo('manage-content');

        foreach ([User::factory()->create(), $moderator->fresh()] as $user) {
            Sanctum::actingAs($user);

            $this->getJson($this->voiceUrl())
                ->assertForbidden();
            $this->post($this->voiceUrl('sample'), ['sample' => $this->upload(25)], ['Accept' => 'application/json'])
                ->assertForbidden();
        }

        // Nor can someone else sing in it.
        $other = User::factory()
            ->create();
        Credits::for($other)->add(50, 'Test');
        $voice = $this->artist
            ->voice()
            ->make();
        $voice->forceFill(['status' => ArtistVoice::READY, 'file' => 'artists/voices/x.mp3', 'seconds' => 25, 'persona_id' => 'p1', 'persona_kind' => ArtistVoice::STYLE_PERSONA]);
        $voice->save();

        $project = Project::query()
            ->forceCreate(['user_id' => $other->id, 'title' => 'Other', 'privacy' => 'private']);
        $track = $project
            ->tracks()
            ->forceCreate(['title' => 'Other', 'privacy' => 'private']);

        Sanctum::actingAs($other);

        $this->postJson("/api/creator/projects/{$project->id}/tracks/{$track->id}/ai/music", [
            'prompt' => 'Warm highlife',
            'seconds' => 30,
            'voice_artist_id' => $this->artist->id,
        ])->assertJsonValidationErrors('voice_artist_id');
    }

    public function test_minimax_15_sings_with_the_sample_itself(): void
    {
        config(['ai.providers.suno.key' => null]);
        AiSettings::update(['ai_music_model' => MiniMaxMusic15::ID]);
        Sanctum::actingAs($this->owner);

        $this->post($this->voiceUrl('sample'), ['sample' => $this->upload(20)], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.check', null)
            ->assertJsonPath('data.studio', true);

        Http::fake([
            self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/voice']]),
            self::REPLICATE . '/models/minimax/music-1.5/predictions' => Http::response(['id' => 'p1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/song.mp3'], 201),
            'https://replicate.delivery/*' => Http::response((string) file_get_contents(base_path('tests/fixtures/audio.mp3')), 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->postJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/ai/music", [
            'prompt' => 'Warm highlife with horns',
            'lyrics' => 'Dance with me tonight',
            'voice_artist_id' => $this->artist->id,
        ])->assertStatus(201);

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/models/minimax/music-1.5/predictions')
            && ($request['input']['voice_file'] ?? null) === self::REPLICATE . '/files/voice');
    }

    public function test_models_that_cannot_follow_a_voice_refuse_one(): void
    {
        AiSettings::update(['ai_music_model' => 'elevenlabs/music']);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/ai/music", [
            'prompt' => 'Warm highlife',
            'seconds' => 30,
            'voice_artist_id' => $this->artist->id,
        ])->assertJsonValidationErrors('voice_artist_id');
    }

    public function test_the_ai_makes_a_sample_and_suno_builds_a_voice_from_it(): void
    {
        Sanctum::actingAs($this->owner);
        Http::fake([
            ...$this->sunoSongs(20),
            self::SUNO . '/api/v1/generate/generate-persona' => Http::response(['code' => 200, 'data' => ['personaId' => 'persona-1']]),
        ]);

        $this->putJson($this->voiceUrl(), ['description' => 'Warm female alto with a light rasp'])
            ->assertStatus(202)
            ->assertJsonPath('data.description', 'Warm female alto with a light rasp');

        $this->postJson($this->voiceUrl('generate'))
            ->assertStatus(202);

        $job = AiJob::query()
            ->where('user_id', $this->owner->id)
            ->where('kind', 'voice')
            ->firstOrFail();
        $this->assertSame(AiJob::SUCCEEDED, $job->status);
        $this->assertSame(12, $job->credits);

        $voice = $this->artist
            ->voice()
            ->firstOrFail();
        $this->assertSame(ArtistVoice::READY, $voice->status);
        $this->assertSame(ArtistVoice::GENERATED, $voice->source);
        $this->assertSame('persona-1', $voice->persona_id);
        $this->assertSame(ArtistVoice::STYLE_PERSONA, $voice->persona_kind);
        $this->assertEqualsWithDelta(20, $voice->seconds, 0.2);

        Http::assertSent(fn(Request $request) => $request->url() === self::SUNO . '/api/v1/generate'
            && $request['customMode'] === true
            && $request['instrumental'] === false
            && str_contains($request['style'], 'A cappella')
            && str_contains($request['style'], 'Warm female alto'));
        Http::assertSent(fn(Request $request) => $request->url() === self::SUNO . '/api/v1/generate/generate-persona'
            && $request['taskId'] === 'song-task'
            && $request['audioId'] === 'audio-1'
            && abs($request['vocalEnd'] - 20) < 0.2);

        // Removing the sample keeps the description.
        $this->deleteJson($this->voiceUrl())
            ->assertStatus(202)
            ->assertJsonPath('data.status', ArtistVoice::EMPTY)
            ->assertJsonPath('data.description', 'Warm female alto with a light rasp');
    }

    public function test_the_sample_plays_from_its_signed_link(): void
    {
        config(['ai.providers.suno.key' => null]);
        Sanctum::actingAs($this->owner);

        $url = $this->post($this->voiceUrl('sample'), ['sample' => $this->upload(21)], ['Accept' => 'application/json'])
            ->json('data.sampleUrl');

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
        $this->get("/api/artists/{$this->artist->id}/voice/audio")
            ->assertForbidden();
    }
}
