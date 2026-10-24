<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use Illuminate\Console\Command;

class ListAiJobs extends Command
{
    protected $signature = '
        ai:jobs
            {--status= : Only queued, running, succeeded or failed jobs}
            {--failures : Only jobs where something went wrong, including steps that failed in finished jobs}
            {--u|user= : Only jobs of this user id}
            {--page=1 : The page to start on}
            {--per-page=20 : Jobs per page (max 100)}
    ';

    protected $description = 'List AI jobs a page at a time, newest first, with what went wrong';

    public function handle(): int
    {
        $statuses = [AiJob::QUEUED, AiJob::RUNNING, AiJob::SUCCEEDED, AiJob::FAILED];
        $status = $this->option('status');

        if ($status && ! in_array($status, $statuses, true)) {
            $this->error('The status must be one of: '.implode(', ', $statuses).'.');

            return self::INVALID;
        }

        $perPage = max(1, min(100, (int) $this->option('per-page')));
        $page = max(1, (int) $this->option('page'));

        do {
            $jobs = AiJob::query()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($this->option('failures'), fn ($query) => $query->whereNotNull('failure'))
                ->when($this->option('user'), fn ($query, $user) => $query->where('user_id', $user))
                ->latest('id')
                ->paginate($perPage, ['*'], 'page', $page);

            if ($jobs->isEmpty()) {
                $this->info($page > 1 ? 'There are no jobs on this page.' : 'No jobs match.');

                return self::SUCCESS;
            }

            $this->table(
                ['ID', 'User', 'Kind', 'Status', 'Credits', 'Started', 'What went wrong'],
                $jobs->getCollection()->map(fn (AiJob $job) => [
                    $job->id,
                    $job->user_id,
                    $job->kind,
                    $job->status,
                    $job->credits,
                    $job->started_at?->toDateTimeString() ?? '-',
                    $job->failure ?? '-',
                ])
            );

            $this->line("Page {$jobs->currentPage()} of {$jobs->lastPage()}, {$jobs->total()} jobs.");
            $page++;
        } while ($jobs->hasMorePages() && $this->input->isInteractive() && $this->confirm('Show the next page?', true));

        return self::SUCCESS;
    }
}
