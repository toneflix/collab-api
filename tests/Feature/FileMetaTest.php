<?php

namespace Tests\Feature;

use App\Jobs\TagAudio;
use App\Models\File;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileMetaTest extends TestCase
{
    use DatabaseTransactions;

    protected string $audio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Bus::fake([TagAudio::class]);

        $this->audio = tempnam(sys_get_temp_dir(), 'meta-') . '.mp3';
        file_put_contents($this->audio, str_repeat('a', 2048));
    }

    protected function tearDown(): void
    {
        @unlink($this->audio);

        parent::tearDown();
    }

    protected function stem(): ProjectTrackStem
    {
        $owner = User::factory()
            ->create();
        $project = Project::query()
            ->forceCreate(['user_id' => $owner->id, 'title' => 'Meta', 'privacy' => 'private']);

        /** @var ProjectTrack $track */
        $track = $project
            ->tracks()
            ->forceCreate(['title' => 'Meta', 'privacy' => 'private']);

        return app(StemFiles::class)
            ->addStem($track, $owner, $this->audio, 'Lead');
    }

    public function test_a_new_stem_saves_its_size_and_mime(): void
    {
        $stem = $this->stem();

        $this->assertSame($stem->src, $stem->file_meta['src']['name']);
        $this->assertSame(2048, $stem->fileSize('src'));
        $this->assertNotNull($stem->fileMime('src'));
    }

    public function test_a_replaced_stem_file_is_read_again(): void
    {
        $stem = $this->stem();

        file_put_contents($this->audio, str_repeat('b', 4096));
        $stem->src = app(StemFiles::class)->put($this->audio);
        $stem->save();

        $this->assertSame(4096, $stem->refresh()->fileSize('src'));
    }

    public function test_stale_meta_is_not_used(): void
    {
        $stem = $this->stem();

        $stem->src = 'other.mp3';

        $this->assertNull($stem->fileSize('src'));
    }

    public function test_an_uploaded_file_saves_its_size_and_mime(): void
    {
        Route::post('/test/file-meta', function (Request $request) {
            $file = File::make();
            $file->meta = ['test' => 'file-meta'];
            $file->file = $request->file('file');
            $file->save();
        });

        $upload = UploadedFile::fake()
            ->createWithContent('notes.pdf', '%PDF-1.4' . str_repeat(' ', 3064));

        $this->post('/test/file-meta', ['file' => $upload]);

        $file = File::query()
            ->whereJsonContains('meta->test', 'file-meta')
            ->firstOrFail();

        $this->assertSame(3072, $file->fileSize('file'));
        $this->assertSame(3072, $file->mediaInfo['size']);
        $this->assertSame('application/pdf', $file->mediaInfo['mime']);
    }

    public function test_the_backfill_command_fills_missing_meta(): void
    {
        $stem = $this->stem();

        ProjectTrackStem::query()
            ->whereKey($stem->id)
            ->update(['file_meta' => null]);

        $this->artisan('files:meta')
            ->assertSuccessful();

        $this->assertSame(2048, $stem->refresh()->fileSize('src'));
    }
}
