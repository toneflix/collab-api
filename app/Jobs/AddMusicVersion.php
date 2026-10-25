<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\AiStudio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Adds the version of a generated song its owner picked to the track,
 * splitting it into stems when the job asked for that.
 */
class AddMusicVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1500;

    public function __construct(public AiJob $aiJob) {}

    public function handle(AiStudio $studio): void
    {
        $studio->addVersion($this->aiJob);
    }

    /**
     * Undo the pick when the queue gives up, so the owner can try again.
     */
    public function failed(?Throwable $e): void
    {
        $job = $this->aiJob->fresh();

        if ($job && isset($job->output['choosing'])) {
            $job
                ->forceFill(['output' => array_diff_key($job->output, ['choosing' => true])])
                ->save();
        }
    }
}
