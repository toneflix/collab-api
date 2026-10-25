<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectTrackDurationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_duration_is_where_the_last_stem_ends(): void
    {
        $user = User::factory()
            ->create();
        $project = Project::factory()
            ->create(['user_id' => $user->id]);

        /** @var ProjectTrack $track */
        $track = $project
            ->tracks()
            ->create(['title' => 'Mix']);
        $track->user_id = $user->id;
        $track->saveQuietly();

        $track
            ->stems()
            ->forceCreate([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'title' => 'Drums',
                'meta' => ['start' => 0, 'duration' => 95.4],
            ]);
        $track
            ->stems()
            ->forceCreate([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'title' => 'Vocals',
                'meta' => ['start' => 12, 'end' => 130.6, 'duration' => 118.6],
            ]);

        $this->assertSame(131, $track->fresh()->duration);
    }

    public function test_track_without_stems_has_no_duration(): void
    {
        $project = Project::factory()
            ->create(['user_id' => User::factory()->create()->id]);
        $track = $project
            ->tracks()
            ->create(['title' => 'Empty']);

        $this->assertSame(0, $track->fresh()->duration);
    }
}
