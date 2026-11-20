<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Video\KeyframeDrawer;

/**
 * Draws a new still for one shot of a music video from its prompt, with the
 * video's character sheets so the people still match.
 */
class RedrawStill implements AiTask, HandlesFailure
{
    public function __construct(
        protected KeyframeDrawer $keyframes,
    ) {}

    /**
     * The storyboard rate for the shot's share of a minute, at least 1.
     */
    public function credits(array $input): int
    {
        $seconds = max(0.0, (float) ($input['seconds'] ?? 0));

        return max(1, (int) ceil(AiSettings::videoStoryboardCredits() * $seconds / 60));
    }

    public function describe(array $input): string
    {
        return 'Music video still: ' . ($input['title'] ?? 'track');
    }

    public function engine(array $input): AiEngine
    {
        return AiEngine::REPLICATE;
    }

    public function handle(AiJob $job): array
    {
        $video = MusicVideo::query()
            ->findOrFail($job->input['music_video_id']);
        $shot = $video
            ->shots()
            ->findOrFail($job->input['shot_id']);
        $portrait = $this->keyframes->upload($video->portrait);

        $this->keyframes->redraw($video, $shot, $portrait);

        $video
            ->forceFill(['edited_at' => now()])
            ->save();

        return ['music_video_id' => $video->id, 'shot_id' => $shot->id];
    }

    public function failed(AiJob $job): void
    {
        MusicVideoShot::query()
            ->whereKey($job->input['shot_id'] ?? null)
            ->update(['drawing' => false]);
    }
}
