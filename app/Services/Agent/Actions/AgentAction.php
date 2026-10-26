<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;

/**
 * What one kind of agent step does. A step is advanced until it is done; one
 * that waits on queued work is advanced again on the run's next pass.
 */
interface AgentAction
{
    /**
     * Do the step's next bit of work and say whether it is done. Throw when
     * it can't be done.
     */
    public function advance(AgentStep $step): bool;
}
