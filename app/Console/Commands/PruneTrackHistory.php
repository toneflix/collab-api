<?php

namespace App\Console\Commands;

use App\Enums\Feature;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Song;
use App\Models\SongVersion;
use App\Services\Composer\Songs;
use App\Services\Limits;
use App\Services\Studio\TrackHistory;
use Illuminate\Console\Command;

class PruneTrackHistory extends Command
{
    protected $signature = 'studio:prune-history';

    protected $description = 'Delete track and song versions older than the owner\'s plan keeps, and the removed stems they held';

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

        $songVersions = $this->pruneSongs();

        $this->info("Deleted {$songVersions} old song versions.");

        return self::SUCCESS;
    }

    /**
     * Autosaved Composer song versions older than the writer's plan keeps.
     */
    protected function pruneSongs(): int
    {
        $total = 0;
        $songs = app(Songs::class);

        Song::query()
            ->whereHas('versions', fn($q) => $q->where('kind', '!=', SongVersion::NAMED))
            ->with('user')
            ->chunkById(100, function ($chunk) use ($songs, &$total) {
                foreach ($chunk as $song) {
                    $days = Limits::for($song->user)->get(Feature::HISTORY_DAYS);

                    if ($days > 0) {
                        $total += $songs->prune($song, now()->subDays($days));
                    }
                }
            });

        return $total;
    }
}
