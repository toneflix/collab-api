<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\ProjectTrackVersion;
use App\Models\User;
use App\Services\Studio\ArrangementDiff;
use Database\Seeders\ConfigurationSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $collaborator;

    protected User $stranger;

    protected Project $project;

    protected ProjectTrack $track;

    protected Genre $genre;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        $this->seed(ConfigurationSeeder::class);

        $this->owner = User::factory()->create();
        $this->collaborator = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->project = Project::query()->forceCreate([
            'user_id' => $this->owner->id,
            'title' => 'History test',
            'privacy' => 'private',
        ]);
        $this->project->collaborators()->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'keys', 'accepted' => true]);

        $this->track = $this->project->tracks()->forceCreate(['title' => 'Night Drive', 'privacy' => 'private']);
        $this->genre = Genre::query()->firstOrCreate(['title' => 'Test genre'], ['slug' => 'test-genre']);
        $this->artist = Artist::factory()->create(['user_id' => $this->owner->id]);
    }

    protected function url(string $path = ''): string
    {
        return "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}{$path}";
    }

    protected function addStem(string $title): ProjectTrackStem
    {
        $id = $this->post($this->url('/stems'), [
            'title' => $title,
            'src' => UploadedFile::fake()->create("{$title}.mp3", 40, 'audio/mpeg'),
            'meta' => ['gain' => 1, 'start' => 0],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return ProjectTrackStem::query()->findOrFail($id);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $metas
     */
    protected function save(array $metas, array $extra = []): TestResponse
    {
        return $this->putJson($this->url(), [
            'title' => $this->track->fresh()->title,
            'genre_id' => (string) $this->genre->id,
            'artist_id' => (string) $this->artist->id,
            'stems' => collect($metas)->map(fn ($meta, $id) => ['id' => $id, 'meta' => $meta])->values()->all(),
            ...$extra,
        ]);
    }

    public function test_each_change_is_recorded_with_who_made_it(): void
    {
        Sanctum::actingAs($this->owner);
        $bass = $this->addStem('Bass');

        Sanctum::actingAs($this->collaborator);
        $this->save([$bass->id => ['gain' => 0.4, 'start' => 2]], ['bpm' => 128])->assertAccepted();

        $versions = $this->getJson($this->url('/versions'))
            ->assertOk()
            ->assertJsonPath('currentId', $this->track->versions()->max('id'))
            ->json('data');

        $this->assertCount(3, $versions);

        [$saved, $added, $original] = $versions;

        $this->assertSame(ProjectTrackVersion::ORIGINAL, $original['action']);
        $this->assertNull($original['user']);

        $this->assertSame(ProjectTrackVersion::ADDED, $added['action']);
        $this->assertSame($this->owner->id, $added['user']['id']);
        $this->assertSame('added Bass', $added['summary']);

        $this->assertSame(ProjectTrackVersion::SAVED, $saved['action']);
        $this->assertSame($this->collaborator->id, $saved['user']['id']);
        $this->assertSame('set the tempo to 128 BPM and moved Bass and changed its volume', $saved['summary']);
        $this->assertEqualsCanonicalizing(['moved', 'volume'], $saved['changes'][1]['what']);
    }

    public function test_saving_without_changes_adds_no_version(): void
    {
        Sanctum::actingAs($this->owner);
        $bass = $this->addStem('Bass');
        $meta = json_decode($bass->fresh()->getRawOriginal('meta'), true);

        $this->save([$bass->id => $meta])->assertAccepted();

        $this->assertSame(2, $this->track->versions()->count());
    }

    public function test_quick_uploads_by_one_person_share_a_version(): void
    {
        Sanctum::actingAs($this->owner);
        $this->addStem('Bass');
        $this->addStem('Drums');

        $latest = $this->track->versions()->latest('id')->first();

        $this->assertSame(2, $this->track->versions()->count());
        $this->assertSame('added Bass and Drums', $latest->summary);
    }

    public function test_restoring_brings_back_removed_stems_and_the_old_arrangement(): void
    {
        Sanctum::actingAs($this->owner);
        $bass = $this->addStem('Bass');
        $this->save([$bass->id => ['gain' => 0.8, 'start' => 1]], ['bpm' => 100])->assertAccepted();
        $target = $this->track->versions()->latest('id')->first();
        $file = $bass->fresh()->getRawOriginal('src');

        $this->travel(5)->minutes();

        Sanctum::actingAs($this->collaborator);
        $drums = $this->addStem('Drums');
        $this->save([$bass->id => ['gain' => 0.2, 'start' => 4], $drums->id => ['gain' => 1, 'start' => 0]], ['bpm' => 140])->assertAccepted();
        $this->deleteJson($this->url("/stems/{$bass->id}"))->assertAccepted();

        $this->assertSoftDeleted($bass);
        $this->assertTrue(Storage::disk(config('filesystems.default'))->exists("public/media/music/stem/{$file}"));

        $this->postJson($this->url("/versions/{$target->id}/restore"))
            ->assertAccepted()
            ->assertJsonPath('data.action', ProjectTrackVersion::RESTORED)
            ->assertJsonPath('data.restoredFrom.id', $target->id)
            ->assertJsonPath('data.user.id', $this->collaborator->id)
            ->assertJsonPath('missing', []);

        $restored = $bass->fresh();
        $this->assertFalse($restored->trashed());
        $this->assertSame($file, $restored->getRawOriginal('src'));
        $this->assertEquals(0.8, json_decode($restored->getRawOriginal('meta'), true)['gain']);
        $this->assertSoftDeleted($drums);
        $this->assertEquals(100, $this->track->fresh()->meta['bpm']);

        $this->getJson($this->url('/stems'))->assertOk()->assertJsonCount(1, 'data');

        // The restore itself can be undone.
        $undo = $this->track->versions()->where('id', '<', $this->track->versions()->max('id'))->latest('id')->first();
        $this->postJson($this->url("/versions/{$undo->id}/restore"))->assertAccepted();
        $this->assertSoftDeleted($bass);
        $this->assertFalse($drums->fresh()->trashed());
    }

    public function test_restoring_the_current_version_is_refused(): void
    {
        Sanctum::actingAs($this->owner);
        $this->addStem('Bass');
        $current = $this->track->versions()->latest('id')->first();

        $this->postJson($this->url("/versions/{$current->id}/restore"))->assertUnprocessable();
    }

    public function test_only_the_owner_and_collaborators_see_or_restore_history(): void
    {
        Sanctum::actingAs($this->owner);
        $this->addStem('Bass');
        $original = $this->track->versions()->oldest('id')->first();

        Sanctum::actingAs($this->stranger);
        $this->getJson($this->url('/versions'))->assertForbidden();
        $this->postJson($this->url("/versions/{$original->id}/restore"))->assertForbidden();

        $other = $this->project->tracks()->forceCreate(['title' => 'Other', 'privacy' => 'private']);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/creator/projects/{$this->project->id}/tracks/{$other->id}/versions/{$original->id}/restore")
            ->assertNotFound();
    }

    public function test_old_versions_and_the_stems_only_they_held_are_pruned(): void
    {
        Plan::default()->update(['features' => [...(Plan::default()->features ?? []), Feature::HISTORY_DAYS->value => 30]]);

        Sanctum::actingAs($this->owner);
        $bass = $this->addStem('Bass');
        $file = $bass->getRawOriginal('src');
        $this->deleteJson($this->url("/stems/{$bass->id}"))->assertAccepted();

        $this->travel(5)->minutes();
        $drums = $this->addStem('Drums');
        $this->travel(40)->days();
        $this->deleteJson($this->url("/stems/{$drums->id}"))->assertAccepted();

        $this->artisan('studio:prune-history')->assertSuccessful();

        // Only the latest version is left; it still holds nothing removed.
        $this->assertSame(1, $this->track->versions()->count());
        $this->assertNull(ProjectTrackStem::withTrashed()->find($bass->id));
        $this->assertNull(ProjectTrackStem::withTrashed()->find($drums->id));
        $this->assertFalse(Storage::disk(config('filesystems.default'))->exists("public/media/music/stem/{$file}"));
    }

    public function test_the_diff_reads_legacy_stems_and_clips(): void
    {
        $before = ['bpm' => 120.0, 'timeSignature' => '4/4', 'title' => 'T', 'stems' => [
            ['id' => 1, 'title' => 'Vox', 'src' => 'a.mp3', 'meta' => ['start' => 0, 'cuein' => 0, 'cueout' => 10, 'muted' => false]],
            ['id' => 2, 'title' => 'Keys', 'src' => 'b.mp3', 'meta' => ['clips' => [['start' => 0, 'offset' => 0, 'duration' => 4]]]],
        ]];
        $after = ['bpm' => 120.0, 'timeSignature' => '3/4', 'title' => 'T', 'stems' => [
            ['id' => 1, 'title' => 'Lead vox', 'src' => 'a.mp3', 'meta' => ['start' => 0, 'cuein' => 1, 'cueout' => 10, 'muted' => true]],
            ['id' => 2, 'title' => 'Keys', 'src' => 'b.mp3', 'meta' => ['clips' => [['start' => 0, 'offset' => 0, 'duration' => 2], ['start' => 3, 'offset' => 2, 'duration' => 2]]]],
        ]];

        $diff = ArrangementDiff::between($before, $after);

        $this->assertSame(
            'changed the meter to 3/4, renamed Vox to Lead vox, trimmed and muted Lead vox and 1 more change',
            $diff->summary()
        );
        $this->assertSame(['split'], $diff->changes()[3]['what']);
    }
}
