<?php

namespace App\Jobs;

use App\Services\Sounds\SoundLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Add CC0 sounds from Freesound to the shared library.
 */
class SyncSoundLibrary implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Downloads are spaced out to respect Freesound's limits, so this can take a while. */
    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        SoundLibrary::sync();
    }
}
