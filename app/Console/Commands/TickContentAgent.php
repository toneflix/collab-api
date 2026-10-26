<?php

namespace App\Console\Commands;

use App\Jobs\AdvanceAgentRun;
use App\Models\AgentRun;
use App\Services\Agent\ContentAgent;
use Illuminate\Console\Command;

/**
 * Keeps the content agent moving: starts the next queued run and gives the
 * active one a pass, in case its own follow-up pass was lost.
 */
class TickContentAgent extends Command
{
    protected $signature = 'agent:tick';

    protected $description = 'Start the next queued content agent run and advance the active one';

    public function handle(ContentAgent $agent): int
    {
        $agent->next();

        AgentRun::query()
            ->active()
            ->each(fn(AgentRun $run) => AdvanceAgentRun::dispatch($run));

        return self::SUCCESS;
    }
}
