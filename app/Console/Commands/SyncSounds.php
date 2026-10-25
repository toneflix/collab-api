<?php

namespace App\Console\Commands;

use App\Services\Sounds\SoundLibrary;
use App\Services\Sounds\SoundSettings;
use Illuminate\Console\Command;

class SyncSounds extends Command
{
    protected $signature = 'sounds:sync {--force : Run even when the library is turned off}';

    protected $description = 'Add CC0 sounds from Freesound to the shared sound library';

    public function handle(): int
    {
        if (! $this->option('force') && ! SoundSettings::libraryEnabled()) {
            $this->info('The sound library is turned off.');

            return self::SUCCESS;
        }

        if (SoundSettings::syncPaused()) {
            $this->info('Library sync is paused.');

            return self::SUCCESS;
        }

        if (! SoundSettings::syncQueries()) {
            $this->info('There are no sync searches.');

            return self::SUCCESS;
        }

        $result = SoundLibrary::sync(fn(string $line) => $this->line($line));

        $this->info("Added {$result['added']}, skipped {$result['skipped']}, failed {$result['failed']}.");
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['failed'] && ! $result['added'] ? self::FAILURE : self::SUCCESS;
    }
}
