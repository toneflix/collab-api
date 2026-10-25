<?php

namespace App\Console\Commands;

use App\Jobs\MeasureDuration;
use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MeasureDurations extends Command
{
    protected $signature = 'tracks:durations {--all : Measure tracks that already have a length too}';

    protected $description = 'Queue length measurement for released tracks without one';

    public function handle(): int
    {
        $queued = 0;

        Track::query()
            ->withoutGlobalScopes()
            ->where(fn(Builder $q) => $q->whereNotNull('audio')->orWhereNotNull('project_track_id'))
            ->unless(
                $this->option('all'),
                fn(Builder $q) => $q->where(fn(Builder $q) => $q->whereNull('duration')->orWhere('duration', '<=', 0)),
            )
            ->chunkById(200, function ($tracks) use (&$queued) {
                foreach ($tracks as $track) {
                    MeasureDuration::dispatch($track);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} for measuring.");

        return self::SUCCESS;
    }
}
