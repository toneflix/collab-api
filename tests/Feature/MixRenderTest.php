<?php

namespace Tests\Feature;

use App\Jobs\TagAudio;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Audio\StemAudio;
use App\Services\Studio\Faust\FaustLibrary;
use App\Services\Studio\MixPreview;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

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
        Bus::fake([TagAudio::class]);

        $this->owner = User::factory()
            ->create();
        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Mix test', 'privacy' => 'private']);
        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Mix', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        array_map(fn($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    /**
     * A tone of this many seconds, as WAV.
     */
    protected function tone(float $seconds, int $frequency = 440, float $volume = 0.9, string $layout = 'stereo'): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.wav';
        exec(sprintf(
            'ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=%s*sin(2*PI*%d*t):d=%s:s=44100" -af "aformat=channel_layouts=%s" %s',
            $volume, $frequency, $seconds, $layout, escapeshellarg($path)
        ));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function stem(string $path, array $meta = []): void
    {
        app(StemFiles::class)
            ->addStem($this->track, $this->owner, $path, 'Stem', $meta, 'wav');
    }

    protected function save(array $data = []): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}", [
            'title' => 'Mix',
            'genre_id' => (string) Genre::factory()->create()->id,
            'artist_id' => (string) Artist::factory()->create()->id,
            'render' => true,
            ...$data,
        ])->assertAccepted();
    }

    /**
     * The track's preview as a local file.
     */
    protected function preview(): string
    {
        $track = $this->track->fresh();
        $path = (new Media)->mediaInfo('music_tracks', $track->preview)['path'];

        $local = $this->files[] = tempnam(sys_get_temp_dir(), 'preview-') . '.mp3';
        file_put_contents($local, Storage::disk(config('filesystems.default'))->get($path));

        return $local;
    }

    public function test_saving_with_render_mixes_the_preview_on_the_server(): void
    {
        $this->stem($this->tone(2));
        $this->stem($this->tone(3, 660), ['clips' => [['start' => 1, 'offset' => 0, 'fadeIn' => 0.5, 'fadeOut' => 0.5]]]);

        $this->save(['mix_volume' => 0.8]);

        $track = $this->track->fresh();
        $this->assertSame(MixPreview::READY, $track->meta['mix']['status']);
        $this->assertSame(0.8, $track->meta['mixVolume']);
        $this->assertStringEndsWith('.mp3', $track->preview);

        $audio = app(StemAudio::class);
        $preview = $this->preview();
        $this->assertEqualsWithDelta(4, $audio->probe($preview)['duration'], 0.1);
        // Two tones at 0.9 would clip together; the mix is turned down to just under full scale.
        $this->assertLessThan(0.2, $audio->peak($preview));
        $this->assertGreaterThan(-1.5, $audio->peak($preview));

        $this->getJson("/api/creator/projects/{$track->project_id}/tracks/{$track->id}")
            ->assertJsonPath('data.mix.status', MixPreview::READY)
            ->assertJsonPath('data.mixVolume', 0.8);
    }

    public function test_muted_stems_are_left_out_and_soloed_ones_play_alone(): void
    {
        $this->stem($this->tone(2), ['muted' => true]);
        $this->save();

        $this->assertSame(MixPreview::FAILED, $this->track->fresh()->meta['mix']['status']);

        $this->stem($this->tone(5, 220, 0.25), ['soloed' => 'true']);
        $this->stem($this->tone(8, 330));
        $this->save();

        $this->assertSame(MixPreview::READY, $this->track->fresh()->meta['mix']['status']);
        $this->assertEqualsWithDelta(5, app(StemAudio::class)->probe($this->preview())['duration'], 0.1);
    }

    public function test_every_studio_effect_renders(): void
    {
        $effects = [
            ['type' => 'tf-eq', 'params' => ['low' => 3, 'mid' => -2, 'midFrequency' => 800, 'high' => 4]],
            ['type' => 'tf-reverb', 'params' => ['size' => 1, 'decay' => 3, 'wet' => 0.4]],
            ['type' => 'native-delay', 'params' => ['delayTime' => 0.3, 'feedback' => 0.5, 'wet' => 1]],
            ['type' => 'native-compressor', 'params' => ['threshold' => -20, 'ratio' => 4, 'knee' => 30, 'attack' => 0.003, 'release' => 0.25]],
            ['type' => 'native-filter', 'params' => ['frequency' => 5000, 'q' => 2]],
            ['type' => 'native-filter', 'params' => ['frequency' => 200], 'bypassed' => 'true'],
            ['type' => 'tf-highpass', 'params' => ['frequency' => 80, 'q' => 3]],
            ['type' => 'tf-saturation', 'params' => ['drive' => 18, 'mix' => 0.7]],
            ['type' => 'tf-crusher', 'params' => ['bits' => 4, 'mix' => 0.5]],
            ['type' => 'tf-tremolo', 'params' => ['rate' => 6, 'depth' => 0.6]],
            ['type' => 'tf-chorus', 'params' => ['rate' => 0.8, 'depth' => 0.5, 'mix' => 0.5]],
            ['type' => 'tf-flanger', 'params' => ['rate' => 0.3, 'depth' => 0.7, 'feedback' => 0.5, 'mix' => 0.5]],
            ['type' => 'tf-phaser', 'params' => ['rate' => 0.5, 'depth' => 0.6, 'feedback' => 0.4, 'mix' => 0.5]],
            ['type' => 'tf-width', 'params' => ['width' => 1.6]],
            ['type' => 'tf-autopan', 'params' => ['rate' => 0.5, 'depth' => 0.8]],
            ['type' => 'tf-limiter', 'params' => ['ceiling' => -3, 'release' => 0.1]],
        ];

        $this->stem($this->tone(2, 440, 0.5), ['effects' => json_encode($effects), 'stereoPan' => 0.4]);
        $this->stem($this->tone(2, 550, 0.5, 'mono'), ['effects' => json_encode($effects), 'stereoPan' => -1]);
        $this->save();

        $this->assertSame(MixPreview::READY, $this->track->fresh()->meta['mix']['status']);
    }

    public function test_faust_effects_render_through_their_ladspa_plugins(): void
    {
        $library = app(FaustLibrary::class);

        if (! $library->available()) {
            $this->markTestSkipped('The Faust compiler is not installed.');
        }

        $effects = collect($library->all())
            ->map(fn(array $effect) => [
                'type' => $effect['type'],
                'params' => collect($effect['params'])->mapWithKeys(fn(array $p) => [$p['key'] => $p['init']])->all(),
            ])
            ->all();

        $this->assertNotEmpty($effects);

        $this->stem($this->tone(2, 440, 0.5, 'mono'), ['effects' => json_encode(array_slice($effects, 0, 4))]);
        $this->stem($this->tone(2, 330, 0.5), ['effects' => json_encode(array_slice($effects, 4))]);
        $this->save();

        $this->assertSame(MixPreview::READY, $this->track->fresh()->meta['mix']['status']);
        $this->assertNotNull($library->plugin($effects[0]['type']));
    }

    public function test_master_effects_are_saved_and_rendered_after_the_mix(): void
    {
        $chain = json_encode([
            ['type' => 'tf-eq', 'params' => ['low' => 2, 'mid' => 0, 'midFrequency' => 1000, 'high' => 1]],
            ['type' => 'tf-limiter', 'params' => ['ceiling' => -6, 'release' => 0.1]],
        ]);

        $this->stem($this->tone(2, 440, 0.9));
        $this->save(['master_effects' => $chain]);

        $track = $this->track->fresh();

        $this->assertSame($chain, $track->meta['masterEffects']);
        $this->assertSame(MixPreview::READY, $track->meta['mix']['status']);
    }

    public function test_only_the_newest_render_is_kept(): void
    {
        $this->stem($this->tone(2));
        $this->track
            ->forceFill(['meta' => ['mix' => ['status' => MixPreview::RENDERING, 'id' => 'newer']]])
            ->saveQuietly();

        app(MixPreview::class)
            ->render($this->track, 'older');

        $this->assertNull($this->track->fresh()->preview);
        $this->assertSame(MixPreview::RENDERING, $this->track->fresh()->meta['mix']['status']);
    }
}
