<?php

namespace App\Console\Commands;

use App\Services\Realtime\Realtime;
use Illuminate\Console\Command;

class PruneRealtime extends Command
{
    protected $signature = 'realtime:prune {--age=3600 : Delete events older than this many seconds}';

    protected $description = 'Delete old realtime events from Firebase (does nothing on other drivers)';

    public function handle(): int
    {
        $firebase = Realtime::firebase();

        if (! $firebase) {
            $this->info('Realtime is not using Firebase; nothing to prune.');

            return self::SUCCESS;
        }

        $total = 0;
        do {
            $deleted = $firebase->prune((int) $this->option('age'));
            $total += $deleted;
        } while ($deleted >= 500);

        $this->info("Deleted {$total} old realtime events.");

        return self::SUCCESS;
    }
}
