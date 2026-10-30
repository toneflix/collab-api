<?php

namespace App\Console\Commands;

use App\Jobs\TagStyle;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Ai\AiSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DetectStyleTags extends Command
{
    protected $signature = 'tags:styles {--all : Listen again to audio that was already tagged}';

    protected $description = 'Queue genre and mood detection for tracks and studio mixes without moods';

    public function handle(): int
    {
        if (! AiSettings::autoStyles()) {
            $this->error('Turn on "Detect Genre and Mood" and set its model in the AI settings first.');

            return self::FAILURE;
        }

        $queued = 0;
        $untagged = fn(Builder $q) => $this->option('all') ? $q : $q->whereNull('styled_at');

        ProjectTrack::query()
            ->withoutGlobalScopes()
            ->whereNotNull('preview')
            ->tap($untagged)
            ->chunkById(200, function ($tracks) use (&$queued) {
                foreach ($tracks as $track) {
                    TagStyle::dispatch($track);
                    $queued++;
                }
            });

        Track::query()
            ->withoutGlobalScopes()
            ->where(fn(Builder $q) => $q->whereNotNull('audio')->orWhereNotNull('project_track_id'))
            ->tap($untagged)
            ->chunkById(200, function ($tracks) use (&$queued) {
                foreach ($tracks as $track) {
                    TagStyle::dispatch($track, inherit: ! $track->audio);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} for genre and mood detection.");

        return self::SUCCESS;
    }
}
