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
use App\Services\Ai\Credits;
use App\Services\Ai\Edit\EditWindow;
use App\Services\Ai\Edit\SunoSectionEditor;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiSectionEditTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://suno.test';

    protected const UPLOADS = 'https://uploads.suno.test';

    protected const CDN = 'https://cdn.suno.test';

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
        config([
            'ai.providers.suno.key' => 'suno_test',
            'ai.providers.suno.url' => self::API,
            'ai.providers.suno.upload_url' => self::UPLOADS,
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_edit_credits' => 5, 'ai_suno_model' => 'V5_5']);

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
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Edit test', 'privacy' => 'private']);
        $this->track = $this->project
            ->tracks()
            ->forceCreate(['title' => 'Idea', 'privacy' => 'private']);
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

    protected function stem(float $seconds = 30): ProjectTrackStem
    {
        return app(StemFiles::class)
            ->addStem($this->track, $this->owner, $this->tone($seconds, 440), 'Keys', [
                'start' => 4,
                'duration' => $seconds,
                'gain' => 0.8,
            ]);
    }

    protected function url(ProjectTrackStem $stem): string
    {
        return "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/stems/{$stem->id}/edit";
    }

    /**
     * Suno answering a section replace with an 880 Hz clip this long.
     */
    protected function fakeSuno(float $seconds): void
    {
        $edited = (string) file_get_contents($this->tone($seconds, 880));

        Http::fake([
            self::UPLOADS . '/api/file-stream-upload' => Http::response(['code' => 200, 'data' => ['fileUrl' => self::UPLOADS . '/files/clip.mp3']]),
            self::API . '/api/v1/generate/replace-section' => Http::response(['code' => 200, 'data' => ['taskId' => 'edit-1']]),
            self::API . '/api/v1/generate/record-info*' => Http::response(['code' => 200, 'data' => [
                'taskId' => 'edit-1',
                'status' => 'SUCCESS',
                'response' => ['sunoData' => [['id' => 'audio-1', 'audioUrl' => self::CDN . '/edit.mp3', 'duration' => $seconds]]],
            ]]),
            self::CDN . '/*' => Http::response($edited, 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    /**
     * How loud the audio is above 800 Hz between two times, in dBFS.
     */
    protected function highLevel(string $path, float $from, float $to): float
    {
        $process = new Process([
            'ffmpeg', '-hide_banner', '-nostats', '-i', $path,
            '-af', "atrim=start={$from}:end={$to},highpass=f=800,highpass=f=800,highpass=f=800,highpass=f=800,astats=measure_perchannel=none:measure_overall=RMS_level",
            '-f', 'null', '-',
        ]);
        $process->run();

        preg_match('/RMS level dB:\s*(-?[\d.]+|-inf)/', $process->getErrorOutput(), $match);

        return ($match[1] ?? '-inf') === '-inf' ? -INF : (float) $match[1];
    }

    public function test_a_section_is_rewritten_into_a_copy_of_the_stem_and_the_original_is_muted(): void
    {
        $this->fakeSuno(30.6);
        $stem = $this->stem();
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url($stem), ['prompt' => 'Brighter synth stabs', 'start' => 12, 'end' => 15])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'edit')
            ->assertJsonPath('data.credits', 5)
            ->assertJsonPath('data.section', ['start' => 12, 'end' => 15])
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::SUCCEEDED, $job->status, (string) $job->failure);
        $this->assertSame(95, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/generate/replace-section'
            && $request['uploadUrl'] === self::UPLOADS . '/files/clip.mp3'
            && $request['model'] === 'V5_5'
            && $request['tags'] === 'Brighter synth stabs'
            && $request['prompt'] === '[Instrumental]'
            && $request['fullLyrics'] === '[Instrumental]'
            && $request['infillStartS'] === 8.5
            && $request['infillEndS'] === 18.5);

        $copy = ProjectTrackStem::findOrFail($job->output['stems'][0]);
        $this->assertSame('Keys (AI edit)', $copy->title);
        $this->assertSame(4, (int) $copy->meta['start']);
        $this->assertSame(0.8, (float) $copy->meta['gain']);
        $this->assertSame($stem->id, $copy->meta['ai']['edit']);
        $this->assertSame('suno/V5_5', $copy->meta['ai']['model']);
        $this->assertTrue((bool) $stem->fresh()->meta['muted']);
        $this->assertArrayNotHasKey('muted', $copy->meta->all());
        $this->assertSame($stem->id, $job->output['muted']);

        $audio = app(StemFiles::class)
            ->local($copy);
        $this->files[] = $audio;

        $this->assertEqualsWithDelta(30, app(StemAudio::class)->probe($audio)['duration'], 0.1);
        $this->assertLessThan(-40, $this->highLevel($audio, 2, 10));
        $this->assertGreaterThan(-25, $this->highLevel($audio, 12.2, 14.8));
        $this->assertLessThan(-40, $this->highLevel($audio, 16, 28));
    }

    public function test_lyrics_for_the_section_are_sung(): void
    {
        $this->fakeSuno(30);
        $stem = $this->stem();
        Sanctum::actingAs($this->owner);

        $this
            ->postJson($this->url($stem), ['prompt' => 'Soulful female vocal', 'lyrics' => 'Hold on a little longer', 'start' => 0, 'end' => 6])
            ->assertCreated();

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/api/v1/generate/replace-section'
            && $request['prompt'] === 'Hold on a little longer'
            && $request['infillStartS'] === 0.0
            && $request['infillEndS'] === 10.0);
    }

    public function test_parts_the_engine_cannot_rewrite_are_turned_away_before_charging(): void
    {
        $short = $this->stem(15);
        $stem = $this->stem();
        Sanctum::actingAs($this->owner);

        $this
            ->postJson($this->url($short), ['prompt' => 'Busier drums', 'start' => 2, 'end' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start');
        $this
            ->postJson($this->url($stem), ['prompt' => 'Busier drums', 'start' => 2, 'end' => 20])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');
        $this
            ->postJson($this->url($stem), ['prompt' => 'Busier drums', 'start' => 4, 'end' => 4.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');

        $this->assertSame(0, AiJob::query()->count());
        $this->assertSame(100, Credits::for($this->owner)->total());
    }

    public function test_a_failed_edit_refunds_and_leaves_the_stem_alone(): void
    {
        Http::fake([
            self::UPLOADS . '/api/file-stream-upload' => Http::response(['code' => 200, 'data' => ['fileUrl' => self::UPLOADS . '/files/clip.mp3']]),
            self::API . '/api/v1/generate/replace-section' => Http::response(['code' => 422, 'msg' => 'Infill too long']),
        ]);
        $stem = $this->stem();
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson($this->url($stem), ['prompt' => 'Busier drums', 'start' => 2, 'end' => 8])
            ->json('data.id');

        $this->assertStringContainsString('Suno answered 422: Infill too long', AiJob::findOrFail($id)->failure);
        $this->assertSame(100, Credits::for($this->owner)->total());
        $this->assertSame(1, $this->track->stems()->count());
        $this->assertArrayNotHasKey('muted', $stem->fresh()->meta->all());
    }

    public function test_the_window_grows_to_the_engines_shortest_section_and_gives_it_context(): void
    {
        $editor = new SunoSectionEditor;

        $middle = EditWindow::plan(30, 32, 120, $editor);
        $this->assertSame([26.0, 36.0], [$middle->infillStart, $middle->infillEnd]);
        $this->assertSame([16.0, 46.0], [$middle->clipStart, $middle->clipEnd]);

        $end = EditWindow::plan(110, 120, 120, $editor);
        $this->assertSame([109.94, 120.0], [$end->infillStart, $end->infillEnd]);
        $this->assertSame([89.94, 120.0], [$end->clipStart, $end->clipEnd]);

        $long = EditWindow::plan(10, 40, 70, $editor);
        $this->assertEqualsWithDelta(0.5, $long->infillLength() / $long->clipLength(), 0.01);

        $this->expectException(ValidationException::class);
        EditWindow::plan(10, 50, 70, $editor);
    }
}
