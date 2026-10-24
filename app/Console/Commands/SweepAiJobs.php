<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Services\Ai\AiStudio;
use Illuminate\Console\Command;

/**
 * Fails and refunds AI jobs that stopped without finishing, like when a
 * worker was restarted mid-run.
 */
class SweepAiJobs extends Command
{
    protected $signature = 'ai:sweep {--older-than=40 : Only jobs untouched for this many minutes}';

    protected $description = 'Refund AI jobs that stopped without finishing';

    public function handle(AiStudio $studio): int
    {
        $count = 0;

        AiJob::query()
            ->unfinished()
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->chunkById(100, function ($batch) use ($studio, &$count) {
                foreach ($batch as $job) {
                    $studio->fail($job, 'The job stopped without finishing.');
                    $count++;
                }
            });

        $this->info("Refunded {$count} AI jobs.");

        return self::SUCCESS;
    }
}
