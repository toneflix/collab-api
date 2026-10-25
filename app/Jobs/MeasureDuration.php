<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\Audio\TrackAudio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads the length of a track's audio, or of its studio mix when it has none.
 */
class MeasureDuration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public Track $track) {}

    public function handle(TrackAudio $audio): void
    {
        $audio->measure($this->track->refresh());
    }
}
