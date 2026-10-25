<?php

namespace Tests\Feature;

use App\Jobs\TagAudio;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class StemPrintTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected ProjectTrack $track;

    protected ProjectTrackStem $stem;

    /** @var list<string> */
    protected array $files = [];

    protected const PLUGIN = '[{"type":"wam","url":"https://example.com/plugin/index.js","state":{},"params":{},"bypassed":false}]';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Bus::fake([TagAudio::class]);

        $this->owner = User::factory()
            ->create();
        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Prints', 'privacy' => 'private']);
        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Prints', 'privacy' => 'private']);
        $this->stem = app(StemFiles::class)
            ->addStem($this->track, $this->owner, $this->tone(2), 'Lead', ['effects' => self::PLUGIN], 'wav');
    }

    protected function tearDown(): void
    {
        array_map(fn($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    protected function tone(float $seconds): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'tone-') . '.wav';
        exec(sprintf(
            'ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=0.5*sin(2*PI*440*t):d=%s:s=44100" -af "aformat=channel_layouts=stereo" %s',
            $seconds, escapeshellarg($path)
        ));

        return $path;
    }

    protected function url(string $action = '/print'): string
    {
        return "/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}/stems/{$this->stem->id}{$action}";
    }

    protected function print(float $seconds, float $start = 1, string $key = 'abc')
    {
        return $this->post($this->url(), [
            'audio' => new UploadedFile($this->tone($seconds), 'print.wav', 'audio/wav', null, true),
            'start' => $start,
            'key' => $key,
        ], ['Accept' => 'application/json']);
    }

    protected function mixLength(): float
    {
        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create();

        $this
            ->putJson("/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}", [
                'title' => 'Prints',
                'genre_id' => (string) $genre->id,
                'artist_id' => (string) $artist->id,
                'render' => true,
            ])
            ->assertAccepted();

        $track = $this->track->fresh();
        $path = (new Media)->mediaInfo('music_tracks', $track->preview)['path'];
        $local = $this->files[] = tempnam(sys_get_temp_dir(), 'preview-') . '.mp3';
        file_put_contents($local, Storage::disk(config('filesystems.default'))->get($path));

        return app(StemAudio::class)->probe($local)['duration'];
    }

    public function test_an_editor_saves_a_print_and_a_new_one_replaces_it(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->print(3, 1.5, 'first')
            ->assertAccepted()
            ->assertJsonPath('data.meta.print.start', 1.5)
            ->assertJsonPath('data.meta.print.key', 'first');

        $files = app(StemFiles::class);
        $stem = $this->stem->fresh();
        $first = $stem->meta['print']['file'];
        $this->assertNotNull($this->files[] = $files->localFile($first));

        $this
            ->print(3, 0, 'second')
            ->assertAccepted()
            ->assertJsonPath('data.meta.print.key', 'second');

        $this->assertNull($files->localFile($first));
        $stem = $this->stem->fresh();
        $this->assertNotNull($this->files[] = $files->localFile($stem->meta['print']['file']));
    }

    public function test_saving_the_stem_keeps_its_print(): void
    {
        Sanctum::actingAs($this->owner);

        $this->print(3)->assertAccepted();

        $this
            ->putJson($this->url(''), ['meta' => ['gain' => 0.5, 'effects' => self::PLUGIN, 'print' => ['file' => 'other.wav']]])
            ->assertAccepted()
            ->assertJsonPath('data.meta.gain', 0.5)
            ->assertJsonPath('data.meta.print.key', 'abc');

        $stem = $this->stem->fresh();
        $this->assertNotSame('other.wav', $stem->meta['print']['file']);
    }

    public function test_saving_the_track_keeps_its_stems_prints(): void
    {
        Sanctum::actingAs($this->owner);

        $this->print(3)->assertAccepted();

        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create();

        $this
            ->putJson("/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}", [
                'title' => 'Prints',
                'genre_id' => (string) $genre->id,
                'artist_id' => (string) $artist->id,
                'stems' => [['id' => $this->stem->id, 'meta' => ['gain' => 0.4, 'effects' => self::PLUGIN]]],
            ])
            ->assertAccepted();

        $stem = $this->stem->fresh();
        $this->assertSame(0.4, $stem->meta['gain']);
        $this->assertSame('abc', $stem->meta['print']['key']);
    }

    public function test_the_mix_plays_the_print_while_a_plugin_is_on(): void
    {
        Sanctum::actingAs($this->owner);

        $this->print(3, 1)->assertAccepted();

        $this->assertEqualsWithDelta(4, $this->mixLength(), 0.1);
    }

    public function test_the_mix_plays_the_stem_when_its_plugins_are_off(): void
    {
        Sanctum::actingAs($this->owner);

        $this->print(3, 1)->assertAccepted();
        $this->stem->refresh();
        $meta = collect($this->stem->meta)
            ->except('src')
            ->all();

        $this->stem->meta = [
            ...$meta,
            'effects' => str_replace('"bypassed":false', '"bypassed":true', self::PLUGIN),
        ];
        $this->stem->save();

        $this->assertEqualsWithDelta(2, $this->mixLength(), 0.1);
    }

    public function test_copies_of_a_stem_leave_its_print_behind(): void
    {
        Sanctum::actingAs($this->owner);

        $this->print(3)->assertAccepted();

        $this
            ->postJson($this->url('/duplicate'))
            ->assertCreated()
            ->assertJsonMissingPath('data.meta.print');
    }

    public function test_someone_who_cannot_edit_the_track_cannot_print_its_stems(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this
            ->print(1)
            ->assertForbidden();
    }
}
