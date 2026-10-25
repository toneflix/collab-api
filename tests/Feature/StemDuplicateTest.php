<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StemDuplicateTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected ProjectTrack $track;

    protected ProjectTrackStem $stem;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->owner = User::factory()
            ->create();
        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Copies', 'privacy' => 'private']);
        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Copies', 'privacy' => 'private']);

        $path = tempnam(sys_get_temp_dir(), 'stem-');
        file_put_contents($path, str_repeat('a', 2048));

        $this->stem = app(StemFiles::class)
            ->addStem($this->track, $this->owner, $path, 'Lead', ['gain' => 0.5, 'effects' => '[]', 'order' => 0]);

        @unlink($path);
    }

    protected function url(): string
    {
        return "/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}/stems/{$this->stem->id}/duplicate";
    }

    public function test_an_editor_duplicates_a_stem_with_its_audio_and_settings(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this
            ->postJson($this->url(), ['meta' => ['order' => 1]])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Lead copy')
            ->assertJsonPath('data.meta.gain', 0.5)
            ->assertJsonPath('data.meta.order', 1);

        $copy = ProjectTrackStem::find($response->json('data.id'));
        $files = app(StemFiles::class);

        $this->assertNotSame($this->stem->src, $copy->src);
        $this->assertSame(file_get_contents($files->local($this->stem)), file_get_contents($files->local($copy)));
        $this->assertSame(2, $this->track->stems()->count());
    }

    public function test_someone_who_cannot_edit_the_track_cannot_duplicate_its_stems(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this
            ->postJson($this->url())
            ->assertForbidden();
    }
}
