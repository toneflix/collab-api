<?php

namespace App\Console\Commands;

use App\Models\Track;
use App\Services\Releases\TrackOrigins;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RankTracks extends Command
{
    protected $signature = 'tracks:rank {--all : Rank tracks that already have a rank too}';

    protected $description = 'Rank released tracks as collaboration, recorded or agentic, and send direct uploads to the AI moderator when that is on';

    public function handle(TrackOrigins $origins): int
    {
        $ranked = 0;

        Track::query()
            ->withoutGlobalScopes()
            ->where(fn(Builder $q) => $q->whereNull('origin_by')->orWhere('origin_by', '!=', TrackOrigins::ADMIN))
            ->unless($this->option('all'), fn(Builder $q) => $q->whereNull('origin'))
            ->chunkById(200, function ($tracks) use ($origins, &$ranked) {
                foreach ($tracks as $track) {
                    $origins->refresh($track);
                    $ranked++;
                }
            });

        $this->info("Checked {$ranked} tracks.");

        return self::SUCCESS;
    }
}
