<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Project;
use App\Models\Release;
use App\Services\Agent\AgentDescriptions;

/**
 * Describes whatever of an existing project, or a release with no project,
 * has no description.
 */
class WriteDescriptions implements AgentAction
{
    public function __construct(protected AgentDescriptions $descriptions) {}

    public function advance(AgentStep $step): bool
    {
        if ($step->output['described'] ?? false) {
            return true;
        }

        $input = $step->input;

        if ($input['project_id'] ?? null) {
            $project = Project::query()->findOrFail($input['project_id']);

            $this->descriptions->fill($step->run, $project);
        } else {
            $release = Release::query()
                ->withoutGlobalScopes()
                ->findOrFail($input['release_id']);

            $this->descriptions->fillRelease($step->run, $release);
        }

        $step->remember(['described' => true]);

        return true;
    }
}
