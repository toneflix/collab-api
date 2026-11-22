<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\TrackCanvas;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Video\CanvasMaker;

/**
 * Makes a looping canvas for a released track from its artwork.
 */
class GenerateCanvas implements AiTask, HandlesFailure
{
    public function __construct(protected CanvasMaker $maker) {}

    public function credits(array $input): int
    {
        return AiSettings::canvasCredits();
    }

    public function describe(array $input): string
    {
        return 'Canvas: ' . ($input['title'] ?? 'track');
    }

    public function engine(array $input): AiEngine
    {
        return AiEngine::REPLICATE;
    }

    public function handle(AiJob $job): array
    {
        $canvas = TrackCanvas::query()
            ->with('track.release')
            ->findOrFail($job->input['canvas_id']);

        $this->maker->make($canvas);

        return ['canvas_id' => $canvas->id];
    }

    public function failed(AiJob $job): void
    {
        TrackCanvas::query()
            ->whereKey($job->input['canvas_id'] ?? null)
            ->where('status', TrackCanvas::DRAWING)
            ->update(['status' => TrackCanvas::FAILED, 'error' => 'The canvas couldn\'t be made.']);
    }
}
