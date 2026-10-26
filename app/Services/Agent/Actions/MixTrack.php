<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\ProjectTrack;
use App\Services\Studio\MixPreview;
use RuntimeException;

/**
 * Renders a track's preview mix, the audio its release plays.
 */
class MixTrack implements AgentAction
{
    public function __construct(protected MixPreview $preview) {}

    public function advance(AgentStep $step): bool
    {
        $track = ProjectTrack::query()->findOrFail($step->from($step->input['source'], 'project_track_id'));

        if (empty($step->output['requested'])) {
            $this->preview->queue($track);
            $step->remember(['requested' => true]);

            return false;
        }

        $state = MixPreview::state($track);

        if (($state['status'] ?? null) === MixPreview::FAILED) {
            throw new RuntimeException('Mix: ' . ($state['error'] ?? 'the preview couldn\'t be mixed.'));
        }

        return ($state['status'] ?? null) === MixPreview::READY;
    }
}
