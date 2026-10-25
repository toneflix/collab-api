<?php

namespace App\Jobs;

use App\Models\ProjectTrack;
use App\Services\Studio\MixPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Renders a project track's preview mix with FFmpeg.
 */
class RenderMix implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public ProjectTrack $track,
        public string $id,
    ) {}

    public function handle(MixPreview $preview): void
    {
        $preview->render($this->track, $this->id);
    }

    public function failed(Throwable $error): void
    {
        app(MixPreview::class)->fail($this->track, $this->id, $error);
    }
}
