<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Composer\Words\CmuDictionary;
use Illuminate\Console\Command;

class ImportWords extends Command
{
    protected $signature = 'words:import
        {--path= : A cmudict file (plain or gzipped) instead of the bundled one}
        {--if-empty : Only import when the words table is empty}';

    protected $description = 'Load the CMU Pronouncing Dictionary into the words table for Composer';

    public function handle(CmuDictionary $dictionary): int
    {
        if ($this->option('if-empty') && Word::query()->exists()) {
            $this->info('The dictionary is already loaded.');

            return self::SUCCESS;
        }

        $total = $dictionary->import(
            $this->option('path') ?: null,
            fn(int $count) => $this->output->write("\rImported {$count} words"),
        );

        $this->newLine();
        $this->info("Imported {$total} words.");

        return self::SUCCESS;
    }
}
