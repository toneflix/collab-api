<?php

namespace App\Jobs;

use App\Models\MusicVideoShort;
use App\Services\Ai\Video\VideoShorts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Cuts a vertical short out of a finished music video.
 */
class CutShort implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public MusicVideoShort $short) {}

    public function handle(VideoShorts $shorts): void
    {
        $shorts->cut($this->short);
    }

    public function failed(Throwable $error): void
    {
        app(VideoShorts::class)
            ->fail($this->short, $error);
    }
}
