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
 * Runs one AI job. It isn't retried, since each try is paid for.
 */
class RunAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 2100;

    public function __construct(public AiJob $aiJob) {}

    public function handle(AiStudio $studio): void
    {
        $studio->run($this->aiJob);
    }

    /**
     * A job that never started is refunded here. One that started and stopped
     * is left to `ai:sweep`, as the queue can also give up on a job that is
     * still running.
     */
    public function failed(?Throwable $e): void
    {
        $job = $this->aiJob->fresh();

        if ($job?->status === AiJob::QUEUED) {
            app(AiStudio::class)
                ->fail($job, $e ?? 'The job stopped.');
        }
    }
}
