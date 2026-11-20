<?php

namespace App\Console\Commands;

use App\Models\MusicVideo;
use App\Services\Ai\Tasks\RenderVideo;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class RestitchVideos extends Command
{
    protected $signature = 'videos:restitch {videos?* : Music video ids} {--all : Every finished music video}';

    protected $description = 'Join finished music videos\' clips over their songs again, free, without rendering any shot';

    public function handle(RenderVideo $render): int
    {
        $ids = $this->argument('videos');

        if (! $ids && ! $this->option('all')) {
            $this->error('Give music video ids or --all.');

            return self::FAILURE;
        }

        $done = 0;
        $failed = 0;

        MusicVideo::query()
            ->where('status', MusicVideo::READY)
            ->when($ids, fn($query) => $query->whereKey($ids))
            ->chunkById(20, function (Collection $videos) use ($render, &$done, &$failed) {
                foreach ($videos as $video) {
                    try {
                        $render->restitch($video);
                        $done++;
                        $this->line("Video {$video->id} stitched again.");
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn("Video {$video->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Stitched {$done} videos again, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
