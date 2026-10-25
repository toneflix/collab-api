<?php

namespace App\Console\Commands;

use App\Jobs\TagAudio;
use App\Models\ProjectTrack;
use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DetectAudioTags extends Command
{
    protected $signature = 'tags:detect {--all : Detect again on audio that already has detected tags}';

    protected $description = 'Queue tempo and key detection for tracks and studio mixes without tags';

    public function handle(): int
    {
        $queued = 0;
        $untagged = fn(Builder $q) => $this->option('all')
            ? $q->where(fn(Builder $q) => $q->whereNull('tags_source')->orWhere('tags_source', Track::TAGS_DETECTED))
            : $q->whereNull('tagged_at');

        Track::query()->withoutGlobalScopes()
            ->where(fn(Builder $q) => $q->whereNotNull('audio')->orWhereNotNull('project_track_id'))
            ->tap($untagged)
            ->chunkById(200, function ($tracks) use (&$queued) {
                foreach ($tracks as $track) {
                    TagAudio::dispatch($track);
                    $queued++;
                }
            });

        ProjectTrack::query()->withoutGlobalScopes()
            ->whereNotNull('preview')
            ->tap($untagged)
            ->chunkById(200, function ($tracks) use (&$queued) {
                foreach ($tracks as $track) {
                    TagAudio::dispatch($track);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} for detection.");

        return self::SUCCESS;
    }
}
