<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Audio\StemAudio;
use App\Services\Studio\MixPreview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class StudioMixUploadTest extends TestCase
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
        Bus::fake();

        $this->owner = User::factory()
            ->create();

        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Mixes', 'privacy' => 'private']);

        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Mixes', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        array_map(fn($file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    /**
     * A stereo tone as a local file, at `amplitude` of full scale.
     */
    protected function tone(float $seconds, string $ext = 'flac', float $amplitude = 0.5): string
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'mix-') . '.' . $ext;

        exec(sprintf(
            'ffmpeg -loglevel error -y -f lavfi -i "aevalsrc=%s*sin(2*PI*440*t):d=%s:s=44100" -af "aformat=channel_layouts=stereo" %s',
            $amplitude,
            $seconds,
            escapeshellarg($path),
        ));

        return $path;
    }

    protected function upload(string $path, string $mime = 'audio/flac')
    {
        $url = "/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}/mix";

        return $this->post($url, [
            'audio' => new UploadedFile($path, basename($path), $mime, null, true),
        ], ['Accept' => 'application/json']);
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

    public function test_an_editor_uploads_a_mix_that_becomes_the_preview(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->upload($this->tone(3))
            ->assertAccepted()
            ->assertJsonPath('data.mix.status', MixPreview::READY);

        $info = app(StemAudio::class)->probe($this->preview());

        $this->assertEqualsWithDelta(3, $info['duration'], 0.1);
    }

    public function test_a_mix_can_be_wav(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->upload($this->tone(2, 'wav'), 'audio/wav')
            ->assertAccepted();

        $this->assertEqualsWithDelta(2, app(StemAudio::class)->probe($this->preview())['duration'], 0.1);
    }

    public function test_a_mix_that_would_clip_is_turned_down(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->upload($this->tone(2, 'wav', 1.4), 'audio/wav')
            ->assertAccepted();

        $this->assertLessThanOrEqual(0.0, app(StemAudio::class)->peak($this->preview()));
    }

    public function test_an_upload_replaces_a_render_in_progress(): void
    {
        Sanctum::actingAs($this->owner);

        $this->track->meta = ['mix' => ['status' => MixPreview::RENDERING, 'id' => 'older']];
        $this->track->saveQuietly();

        $this
            ->upload($this->tone(1))
            ->assertAccepted();

        $mix = $this->track->fresh()->meta['mix'];

        $this->assertSame(MixPreview::READY, $mix['status']);
        $this->assertNotSame('older', $mix['id']);
    }

    public function test_only_audio_is_accepted(): void
    {
        Sanctum::actingAs($this->owner);

        $text = $this->files[] = tempnam(sys_get_temp_dir(), 'mix-') . '.txt';

        file_put_contents($text, 'not audio');

        $this
            ->upload($text, 'text/plain')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audio');
    }

    public function test_someone_who_cannot_edit_the_track_cannot_upload_its_mix(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this
            ->upload($this->tone(1))
            ->assertForbidden();
    }
}
