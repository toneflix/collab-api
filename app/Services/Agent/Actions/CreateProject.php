<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;

/**
 * Starts the private studio project an album's tracks are made in.
 */
class CreateProject implements AgentAction
{
    public function advance(AgentStep $step): bool
    {
        if ($step->output['project_id'] ?? null) {
            return true;
        }

        $project = $step->run->owner
            ->projects()
            ->create([
                'title' => $step->input['title'],
                'description' => $step->input['description'] ?: null,
                'privacy' => $step->run->option('project_privacy', 'private'),
            ]);

        $step->remember(['project_id' => $project->id]);

        return true;
    }
}
