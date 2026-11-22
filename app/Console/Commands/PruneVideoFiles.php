<?php

namespace App\Console\Commands;

use App\Services\Ai\Video\CanvasStorage;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Console\Command;

class PruneVideoFiles extends Command
{
    protected $signature = 'videos:prune-files';

    protected $description = 'Delete the folders of music videos and track canvases that no longer exist';

    public function handle(VideoStorage $storage, CanvasStorage $canvases): int
    {
        $deleted = $storage->pruneOrphans();
        $canvasesDeleted = $canvases->pruneOrphans();

        $this->info("Deleted the files of {$deleted} removed music videos and {$canvasesDeleted} removed canvases.");

        return self::SUCCESS;
    }
}
