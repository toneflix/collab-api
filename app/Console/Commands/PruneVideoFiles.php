<?php

namespace App\Console\Commands;

use App\Services\Ai\Video\VideoStorage;
use Illuminate\Console\Command;

class PruneVideoFiles extends Command
{
    protected $signature = 'videos:prune-files';

    protected $description = 'Delete the folders of music videos that no longer exist';

    public function handle(VideoStorage $storage): int
    {
        $deleted = $storage->pruneOrphans();

        $this->info("Deleted the files of {$deleted} removed music videos.");

        return self::SUCCESS;
    }
}
