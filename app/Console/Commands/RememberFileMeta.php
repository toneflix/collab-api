<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\ProjectTrackStem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RememberFileMeta extends Command
{
    protected $signature = 'files:meta';

    protected $description = 'Save the size and mime type of uploaded files and stems';

    public function handle(): int
    {
        $checked = 0;

        foreach ([File::class => 'file', ProjectTrackStem::class => 'src'] as $model => $field) {
            $model::query()
                ->withoutGlobalScopes()
                ->whereNotNull($field)
                ->whereNull('file_meta')
                ->chunkById(200, function (Collection $files) use (&$checked) {
                    foreach ($files as $file) {
                        $file->rememberFileMeta();
                        $checked++;
                    }
                });
        }

        $this->info("Checked {$checked} files.");

        return self::SUCCESS;
    }
}
