<?php

namespace Tests\Feature;

use App\Jobs\RenderMix;
use App\Jobs\TagAudio;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Studio\BrowserMix;
use App\Services\Studio\MixPreview;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class MixRenderTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected ProjectTrack $track;

    /** @var list<string> */
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Bus::fake([TagAudio::class, RenderMix::class]);

        $this->owner = User::factory()
            ->create();

        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Mix test', 'privacy' => 'private']);

        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Mix', 'privacy' => 'private', 'meta' => ['bpm' => 96, 'mixVolume' => 0.8]]);
    }

    protected function tearDown(): void
    {
        array_map(fn($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    /**
     * A stereo tone of this many seconds, as FLAC.
     */
    protected function tone(float $seconds): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.flac';

        exec(sprintf(
            'ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*440*t):d=%s:s=44100" -af "aformat=channel_layouts=stereo" %s',
            $seconds,
            escapeshellarg($path),
        ));

        return $path;
    }

    protected function requested(string $id): void
    {
        $this->track->meta = [...$this->track->meta, 'mix' => ['status' => MixPreview::RENDERING, 'id' => $id]];
        $this->track->saveQuietly();
    }

    /**
     * Stand in for Chrome: the render page uploads a mix of `$seconds`, or
     * finds nothing to mix.
     */
    protected function fakeBrowser(?float $seconds): void
    {
        $this->mock(BrowserMix::class, function (MockInterface $mock) use ($seconds) {
            $mock
                ->shouldReceive('render')
                ->andReturnUsing(function (ProjectTrack $track, string $id) use ($seconds) {
                    if ($seconds === null) {
                        return BrowserMix::EMPTY;
                    }

                    app(MixPreview::class)
                        ->upload($track, $this->tone($seconds), $id);

                    return BrowserMix::DONE;
                });
        });
    }

    public function test_saving_with_render_asks_for_a_mix(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->putJson("/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}", [
                'title' => 'Mix',
                'genre_id' => (string) Genre::factory()->create()->id,
                'artist_id' => (string) Artist::factory()->create()->id,
                'render' => true,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.mix.status', MixPreview::RENDERING);

        Bus::assertDispatched(RenderMix::class, fn(RenderMix $job) => $job->track->is($this->track));
    }

    public function test_the_studio_mix_from_chrome_becomes_the_preview(): void
    {
        $this->fakeBrowser(2);
        $this->requested('first');

        (new RenderMix($this->track, 'first'))
            ->handle(app(MixPreview::class));

        $track = $this->track->fresh();

        $this->assertSame(MixPreview::READY, $track->meta['mix']['status']);
        $this->assertSame('first', $track->meta['mix']['id']);
        $this->assertStringEndsWith('.mp3', $track->preview);
    }

    public function test_a_track_with_nothing_to_mix_fails_with_a_reason(): void
    {
        $this->fakeBrowser(null);
        $this->requested('first');

        app(MixPreview::class)
            ->render($this->track, 'first');

        $mix = $this->track->fresh()->meta['mix'];

        $this->assertSame(MixPreview::FAILED, $mix['status']);
        $this->assertSame('There is nothing to mix.', $mix['error']);
    }

    public function test_an_older_request_is_not_rendered(): void
    {
        $this->mock(BrowserMix::class, fn(MockInterface $mock) => $mock->shouldNotReceive('render'));
        $this->requested('newer');

        app(MixPreview::class)
            ->render($this->track, 'older');

        $this->assertNull($this->track->fresh()->preview);
        $this->assertSame(MixPreview::RENDERING, $this->track->fresh()->meta['mix']['status']);
    }

    public function test_a_failed_render_says_so(): void
    {
        $this->requested('first');

        (new RenderMix($this->track, 'first'))
            ->failed(new RuntimeException('Chrome crashed'));

        $this->assertSame(MixPreview::FAILED, $this->track->fresh()->meta['mix']['status']);
    }

    public function test_the_render_page_reads_the_track_from_its_signed_link(): void
    {
        $tone = $this->tone(1);

        app(StemFiles::class)
            ->addStem($this->track, $this->owner, $tone, 'Lead', ['gain' => 0.5, 'effects' => '[]'], 'flac');

        $url = URL::temporarySignedRoute('studio.render.show', now()->addMinutes(5), ['track' => $this->track->id]);

        $this
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.bpm', 96)
            ->assertJsonPath('data.mixVolume', 0.8)
            ->assertJsonPath('data.stems.0.title', 'Lead')
            ->assertJsonPath('data.stems.0.meta.gain', 0.5)
            ->assertJsonPath('plugins', false);

        $this
            ->getJson("/api/studio/render/tracks/{$this->track->id}")
            ->assertForbidden();
    }

    public function test_the_render_page_uploads_to_its_signed_link(): void
    {
        $this->requested('first');

        $url = URL::temporarySignedRoute('studio.render.store', now()->addMinutes(5), ['track' => $this->track->id, 'mix' => 'first']);
        $file = new UploadedFile($this->tone(2), 'mix.flac', 'audio/flac', null, true);

        $this
            ->post($url, ['audio' => $file], ['Accept' => 'application/json'])
            ->assertAccepted();

        $this->assertSame(MixPreview::READY, $this->track->fresh()->meta['mix']['status']);
        $this->assertStringEndsWith('.mp3', $this->track->fresh()->preview);
    }

    public function test_an_upload_for_an_older_request_is_ignored(): void
    {
        $this->requested('newer');

        $url = URL::temporarySignedRoute('studio.render.store', now()->addMinutes(5), ['track' => $this->track->id, 'mix' => 'older']);
        $file = new UploadedFile($this->tone(1), 'mix.flac', 'audio/flac', null, true);

        $this
            ->post($url, ['audio' => $file], ['Accept' => 'application/json'])
            ->assertAccepted();

        $this->assertNull($this->track->fresh()->preview);
        $this->assertSame('newer', $this->track->fresh()->meta['mix']['id']);
    }

    public function test_the_render_page_is_the_apps_with_both_signed_links(): void
    {
        config(['app.frontend_url' => 'https://music.example/', 'studio.render.url' => null]);

        $url = app(BrowserMix::class)
            ->pageUrl($this->track, 'first', 60);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://music.example/studio/render?', $url);
        $this->assertStringContainsString("studio/render/tracks/{$this->track->id}?", $query['data']);
        $this->assertStringContainsString("studio/render/tracks/{$this->track->id}/mix/first?", $query['upload']);
        $this->assertStringContainsString('signature=', $query['upload']);
    }

    public function test_chrome_waits_for_the_render_page_to_finish(): void
    {
        $chrome = collect([config('studio.render.chrome'), 'google-chrome', 'chromium', 'chromium-browser'])
            ->map(fn(string $name) => (new ExecutableFinder)->find($name))
            ->filter()
            ->first();

        if (! $chrome) {
            $this->markTestSkipped('Chrome is not installed.');
        }

        // A page that finishes like the render page does, a moment after it loads.
        $page = $this->files[] = tempnam(sys_get_temp_dir(), 'render-') . '.html';

        file_put_contents($page, '<script>setTimeout(() => (window.tfMix = { status: "done" }), 300)</script>');
        config(['studio.render.chrome' => $chrome, 'studio.render.url' => 'file://' . $page, 'studio.render.timeout' => 30]);

        $this->assertSame(BrowserMix::DONE, app(BrowserMix::class)->render($this->track, 'first'));

        file_put_contents($page, '<script>window.tfMix = { status: "failed", error: "No audio" }</script>');

        $this->expectExceptionMessage('No audio');

        app(BrowserMix::class)
            ->render($this->track, 'first');
    }
}
