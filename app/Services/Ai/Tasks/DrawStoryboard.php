<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Video\KeyframeDrawer;
use App\Services\Ai\Video\ScenePlanner;
use App\Services\Ai\Video\VideoStorage;
use RuntimeException;

/**
 * Plans a music video's shots and draws the still each one starts from, for
 * the owner to check before paying for the render.
 */
class DrawStoryboard implements AiTask, HandlesFailure
{
    public function __construct(
        protected ScenePlanner $planner,
        protected KeyframeDrawer $keyframes,
        protected VideoStorage $storage,
    ) {}

    public function credits(array $input): int
    {
        return AiSettings::videoStoryboardCredits() * max(1, (int) ($input['minutes'] ?? 1));
    }

    public function describe(array $input): string
    {
        return 'Music video storyboard: ' . ($input['title'] ?? 'track');
    }

    public function engine(array $input): AiEngine
    {
        return AiEngine::REPLICATE;
    }

    public function handle(AiJob $job): array
    {
        if (! $this->planner->available()) {
            throw new RuntimeException('AI writing isn\'t set up yet.');
        }

        $video = MusicVideo::query()
            ->with('track')
            ->findOrFail($job->input['music_video_id']);

        $plan = $this->planner->plan($video, RenderVideo::maxShotSeconds());

        $video
            ->forceFill(['style' => $plan['style'], 'status' => MusicVideo::PLANNING, 'error' => null])
            ->save();

        $video
            ->shots()
            ->each(fn(MusicVideoShot $shot) => $this->storage->delete($shot->keyframe));
        $video
            ->shots()
            ->delete();

        foreach ($plan['shots'] as $position => $shot) {
            $video
                ->shots()
                ->create([...$shot, 'position' => $position + 1]);
        }

        $shots = $video
            ->shots()
            ->get();
        $portrait = $this->keyframes->upload($video->portrait);

        $this->keyframes->draw($video, $shots, $portrait);

        $video
            ->forceFill(['status' => MusicVideo::STORYBOARD])
            ->save();

        return ['music_video_id' => $video->id, 'shots' => $shots->count()];
    }

    public function failed(AiJob $job): void
    {
        MusicVideo::query()
            ->whereKey($job->input['music_video_id'] ?? null)
            ->update([
                'status' => MusicVideo::FAILED,
                'error' => 'The storyboard couldn\'t be drawn. Your credits were refunded.',
            ]);
    }
}
