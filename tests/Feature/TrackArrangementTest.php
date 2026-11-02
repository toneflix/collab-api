<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackArrangementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()
            ->create();

        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Arrangement', 'privacy' => 'private']);

        $this->track = $project
            ->tracks()
            ->forceCreate(['title' => 'Song', 'privacy' => 'private', 'meta' => ['bpm' => 100]]);
    }

    /**
     * Save the track with these extra fields.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function save(array $fields): TestResponse
    {
        Sanctum::actingAs($this->owner);

        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create();

        return $this->putJson("/api/creator/projects/{$this->track->project_id}/tracks/{$this->track->id}", [
            'title' => 'Song',
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
            ...$fields,
        ]);
    }

    public function test_markers_tempo_changes_and_master_effects_are_saved_with_the_track(): void
    {
        $arrangement = json_encode([
            'markers' => [['id' => 'm1', 'time' => 8, 'name' => 'Chorus']],
            'tempo' => [['id' => 't1', 'time' => 16, 'bpm' => 120]],
        ]);
        $effects = json_encode([['type' => 'tf-limiter', 'params' => []]]);

        $this
            ->save(['arrangement' => $arrangement, 'master_effects' => $effects])
            ->assertAccepted()
            ->assertJsonPath('data.arrangement', $arrangement)
            ->assertJsonPath('data.masterEffects', $effects)
            ->assertJsonPath('data.bpm', 100);

        $this->assertSame($arrangement, $this->track->fresh()->meta['arrangement']);
    }

    public function test_an_arrangement_that_is_not_json_is_refused(): void
    {
        $this
            ->save(['arrangement' => 'not json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('arrangement');
    }
}
