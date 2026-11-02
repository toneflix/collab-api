<?php

namespace App\Jobs;

use App\Models\ProjectTrackStem;
use App\Services\Studio\InstrumentAudio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Has the studio render an instrument stem's audio, for downloads.
 */
class PrepareStemAudio implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public ProjectTrackStem $stem,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->stem->id;
    }

    public function handle(InstrumentAudio $audio): void
    {
        $audio->file($this->stem);
    }
}
