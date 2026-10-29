<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Project;

/**
 * Adds an instrumental track to an album's project, with the artists it
 * features.
 */
class CreateTrack implements AgentAction
{
    public function advance(AgentStep $step): bool
    {
        if ($step->output['project_track_id'] ?? null) {
            return true;
        }

        $input = $step->input;
        $project = Project::query()->findOrFail($step->from($input['project'], 'project_id'));

        $track = $project
            ->tracks()
            ->create([
                'title' => $input['title'],
                'privacy' => 'private',
                'genre_id' => $input['genre_id'],
                'artist_id' => $step->from($input['artist'], 'artist_id'),
            ]);

        $track->feature(self::featuredIds($step));

        $step->remember(['project_track_id' => $track->id, 'project_id' => $project->id]);

        return true;
    }

    /**
     * The ids of the artists the step's track features, made by earlier steps.
     *
     * @return list<int>
     */
    public static function featuredIds(AgentStep $step): array
    {
        return collect($step->input['featuring'] ?? [])
            ->map(fn(string $key) => (int) $step->from($key, 'artist_id'))
            ->filter()
            ->values()
            ->all();
    }
}
