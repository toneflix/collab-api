<?php

namespace Tests\Feature;

use App\Jobs\RenderMix;
use App\Jobs\TagAudio;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Studio\BrowserMix;
use App\Services\Studio\InstrumentAudio;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use Tests\TestCase;

class InstrumentAudioTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected ProjectTrack $track;

    protected ProjectTrackStem $stem;

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
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Beats', 'privacy' => 'private']);

        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Beat', 'privacy' => 'private', 'meta' => ['bpm' => 90]]);

        $instrument = json_encode([
            'version' => 2,
            'instrument' => ['id' => 'drums'],
            'patterns' => ['p1' => ['name' => 'Beat', 'notes' => [['pitch' => 36, 'start' => 0, 'duration' => 0.25, 'velocity' => 0.8]]]],
            'clips' => [['pattern' => 'p1', 'start' => 0, 'offset' => 0, 'duration' => 2, 'gain' => 1]],
        ]);

        $this->stem = $this->track
            ->stems()
            ->forceCreate([
                'user_id' => $this->owner->id,
                'title' => 'Drums',
                'meta' => ['name' => 'Drums', 'instrument' => $instrument, 'gain' => 0.5, 'stereoPan' => -1, 'effects' => '[]'],
            ]);
    }

    protected function tearDown(): void
    {
        array_map(fn($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    /**
     * A second of tone, as FLAC.
     */
    protected function tone(): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.flac';

        exec(sprintf('ffmpeg -loglevel error -y -f lavfi -i "sine=frequency=440:duration=1" %s', escapeshellarg($path)));

        return $path;
    }

    /**
     * Stand in for Chrome: the render page uploads the stem's audio.
     */
    protected function fakeBrowser(): void
    {
        $this->mock(BrowserMix::class, function (MockInterface $mock) {
            $mock
                ->shouldReceive('renderStem')
                ->once()
                ->andReturnUsing(function (ProjectTrackStem $stem, string $hash) {
                    app(InstrumentAudio::class)
                        ->keep($stem, $this->tone(), $hash);

                    return BrowserMix::DONE;
                });
        });
    }

    public function test_an_instrument_stem_is_rendered_once_for_its_audio(): void
    {
        $this->fakeBrowser();

        $first = app(StemFiles::class)
            ->local($this->stem);
        $this->files[] = $first;

        $this->assertFileExists($first);
        $this->assertSame(InstrumentAudio::hash($this->stem), $this->stem->fresh()->meta['bounce']['hash']);

        // The same notes reuse the file; the mock allows one render only.
        $again = app(StemFiles::class)
            ->local($this->stem->fresh());
        $this->files[] = $again;

        $this->assertFileExists($again);
    }

    public function test_audio_for_notes_that_changed_since_is_not_kept(): void
    {
        $hash = InstrumentAudio::hash($this->stem);

        $this->stem->meta = [...collect($this->stem->meta)->except('src')->all(), 'effects' => '[{"type":"tf-limiter","params":{}}]'];
        $this->stem->saveQuietly();

        $kept = app(InstrumentAudio::class)
            ->keep($this->stem, $this->tone(), $hash);

        $this->assertFalse($kept);
        $this->assertArrayNotHasKey('bounce', $this->stem->fresh()->meta->all());
    }

    public function test_the_render_page_reads_the_stem_alone_and_uploads_its_audio(): void
    {
        $show = URL::temporarySignedRoute('studio.render.stem.show', now()->addMinutes(5), ['stem' => $this->stem->id]);

        $this
            ->getJson($show)
            ->assertOk()
            ->assertJsonPath('data.mixVolume', 1)
            ->assertJsonPath('data.masterEffects', '')
            ->assertJsonCount(1, 'data.stems')
            ->assertJsonPath('data.stems.0.meta.instrument', $this->stem->meta['instrument'])
            ->assertJsonMissingPath('data.stems.0.meta.gain')
            ->assertJsonMissingPath('data.stems.0.meta.stereoPan');

        $this
            ->getJson("/api/studio/render/stems/{$this->stem->id}")
            ->assertForbidden();

        $hash = InstrumentAudio::hash($this->stem);
        $upload = URL::temporarySignedRoute('studio.render.stem.store', now()->addMinutes(5), ['stem' => $this->stem->id, 'hash' => $hash]);
        $file = new UploadedFile($this->tone(), 'stem.flac', 'audio/flac', null, true);

        $this
            ->post($upload, ['audio' => $file], ['Accept' => 'application/json'])
            ->assertAccepted();

        $this->assertNotNull(app(InstrumentAudio::class)->current($this->stem->fresh()));
    }

    public function test_the_render_page_link_for_a_stem_carries_both_signed_links(): void
    {
        config(['app.frontend_url' => 'https://music.example', 'studio.render.url' => null]);

        $url = app(BrowserMix::class)
            ->stemPageUrl($this->stem, 'abc', 60);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://music.example/studio/render?', $url);
        $this->assertStringContainsString("studio/render/stems/{$this->stem->id}?", $query['data']);
        $this->assertStringContainsString("studio/render/stems/{$this->stem->id}/audio/abc?", $query['upload']);
    }

    public function test_instrument_stems_count_as_playable(): void
    {
        $this->assertTrue(
            $this->track
                ->stems()
                ->playable()
                ->whereKey($this->stem->id)
                ->exists(),
        );
        $this->assertTrue(
            ProjectTrack::query()
                ->ready()
                ->whereKey($this->track->id)
                ->exists(),
        );
    }
}
