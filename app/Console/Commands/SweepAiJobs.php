<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Music\MusicVersions;
use Illuminate\Console\Command;

/**
 * Fails and refunds AI jobs that stopped without finishing, like when a
 * worker was restarted mid-run, and deletes song versions nobody picked.
 */
class SweepAiJobs extends Command
{
    protected $signature = 'ai:sweep {--older-than=40 : Only jobs untouched for this many minutes}';

    protected $description = 'Refund AI jobs that stopped without finishing and delete unpicked song versions';

    public function handle(AiStudio $studio, MusicVersions $versions): int
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

        $expired = 0;

        AiJob::query()
            ->where('kind', 'music')
            ->where('status', AiJob::SUCCEEDED)
            ->where('finished_at', '<=', now()->subDays(MusicVersions::KEEP_DAYS))
            ->whereNotNull('output->versions')
            ->whereNull('output->chosen')
            ->whereNull('output->expired')
            ->chunkById(100, function ($batch) use ($versions, &$expired) {
                foreach ($batch as $job) {
                    $versions->forget($job);
                    $job
                        ->forceFill(['output' => [...$job->output, 'expired' => true]])
                        ->save();
                    $expired++;
                }
            });

        $this->info("Deleted the versions of {$expired} songs nobody picked.");

        return self::SUCCESS;
    }
}
