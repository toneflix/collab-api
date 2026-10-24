<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Services\Limits;
use App\Services\Studio\TrackHistory;
use Illuminate\Console\Command;

class PruneTrackHistory extends Command
{
    protected $signature = 'studio:prune-history';

    protected $description = 'Delete track versions older than the project owner\'s plan keeps, and the removed stems they held';

    public function handle(): int
    {
        $total = 0;

        Project::query()
            ->withoutGlobalScopes()
            ->whereHas('tracks.versions')
            ->with('user')
            ->chunkById(100, function ($projects) use (&$total) {
                foreach ($projects as $project) {
                    $days = Limits::for($project->user)->get(Feature::HISTORY_DAYS);

                    if ($days <= 0) {
                        continue;
                    }

                    $cutoff = now()->subDays($days);

                    $project->tracks()->whereHas('versions')->chunkById(100, function ($tracks) use ($cutoff, &$total) {
                        $tracks->each(function (ProjectTrack $track) use ($cutoff, &$total) {
                            $total += TrackHistory::for($track)->prune($cutoff);
                        });
                    });
                }
            });

        $this->info("Deleted {$total} old track versions.");

        return self::SUCCESS;
    }
}
