<?php

namespace App\Listeners;

use App\Contracts\StoresFileMeta;
use Toneflix\LaravelFileable\Events\FileSaved;

/**
 * Saves the size and mime type of files that fileable models store.
 */
class RememberFileMeta
{
    public function handle(FileSaved $event): void
    {
        if ($event->model instanceof StoresFileMeta) {
            $event->model->rememberFileMeta();
        }
    }
}
