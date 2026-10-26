<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Services\Agent\ContentAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One pass over a content agent run.
 */
class AdvanceAgentRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public AgentRun $run) {}

    public function handle(ContentAgent $agent): void
    {
        $agent->advance($this->run);
    }
}
