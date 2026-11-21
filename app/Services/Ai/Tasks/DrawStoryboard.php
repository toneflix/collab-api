<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Video\KeyframeDrawer;
use App\Services\Ai\Video\LocationPlanner;
use App\Services\Ai\Video\ScenePlanner;
use App\Services\Ai\Video\VideoStorage;
use RuntimeException;

/**
 * Plans a music video's characters and shots, draws a character sheet for
 * each character and the stills the shots start from, for the owner to check
 * before paying for the render.
 */
class DrawStoryboard implements AiTask, HandlesFailure
{
    public function __construct(
        protected ScenePlanner $planner,
        protected LocationPlanner $locationPlanner,
        protected KeyframeDrawer $keyframes,
        protected VideoStorage $storage,
    ) {}

    /**
     * The full price for a new storyboard at its mode's rate; a retry pays
     * only for the share of stills it still has to draw.
     */
    public function credits(array $input): int
    {
        $rate = ($input['mode'] ?? MusicVideo::LEGACY) === MusicVideo::LOCATIONS
            ? AiSettings::videoLocationStoryboardCredits()
            : AiSettings::videoStoryboardCredits();
        $credits = $rate * max(1, (int) ($input['minutes'] ?? 1));
        $total = (int) ($input['shots'] ?? 0);

        if (! ($input['resume'] ?? false) || $total <= 0) {
            return $credits;
        }

        return max(1, (int) ceil($credits * (int) ($input['missing'] ?? $total) / $total));
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
        $video = MusicVideo::query()
            ->with('track')
            ->findOrFail($job->input['music_video_id']);

        $planned = $video
            ->shots()
            ->exists();

        if (($job->input['resume'] ?? false) && $planned) {
            $video
                ->forceFill(['status' => MusicVideo::PLANNING, 'error' => null])
                ->save();

            $this->castSinger($video);
        } else {
            $this->plan($video);
        }

        $characters = $video
            ->characters()
            ->get();
        $shots = $video
            ->shots()
            ->get();
        $portrait = $this->keyframes->upload($video->portrait);

        $this->keyframes->draw($video, $characters, $shots, $portrait);

        $video
            ->forceFill(['status' => MusicVideo::STORYBOARD])
            ->save();

        return ['music_video_id' => $video->id, 'shots' => $shots->count()];
    }

    /**
     * Plan the shots afresh, replacing any earlier ones and their stills. The
     * creator's own character sheets stay.
     */
    protected function plan(MusicVideo $video): void
    {
        $planner = $video->mode === MusicVideo::LOCATIONS ? $this->locationPlanner : $this->planner;

        if (! $planner->available()) {
            throw new RuntimeException('AI writing isn\'t set up yet.');
        }

        $plan = $planner->plan($video, RenderVideo::maxShotSeconds($video->quality));

        $video
            ->forceFill([
                'style' => $plan['style'],
                'locations' => $plan['locations'] ?: null,
                'status' => MusicVideo::PLANNING,
                'error' => null,
            ])
            ->save();

        $video
            ->takes()
            ->each(fn(MusicVideoTake $take) => $this->storage->delete($take->file));
        $video
            ->takes()
            ->delete();
        $video
            ->shots()
            ->each(fn(MusicVideoShot $shot) => $this->storage->delete($shot->keyframe));
        $video
            ->shots()
            ->delete();
        $video
            ->characters()
            ->where('provided', false)
            ->each(fn(MusicVideoCharacter $character) => $this->storage->delete($character->sheet));
        $video
            ->characters()
            ->where('provided', false)
            ->delete();

        foreach ($plan['characters'] as $character) {
            $video
                ->characters()
                ->firstOrCreate(['key' => $character['key']], $character);
        }

        foreach ($plan['shots'] as $position => $shot) {
            $video
                ->shots()
                ->create([...$shot, 'position' => $position + 1]);
        }
    }

    /**
     * Give a storyboard planned before character sheets its singer, so the
     * stills still to draw match each other.
     */
    protected function castSinger(MusicVideo $video): void
    {
        $cast = $video
            ->characters()
            ->exists();

        if ($cast) {
            return;
        }

        $video
            ->characters()
            ->create([
                'key' => MusicVideoCharacter::SINGER,
                'name' => 'The singer',
                'description' => $video->style['character'] ?? 'The artist',
                'singer' => true,
            ]);

        $video
            ->shots()
            ->where('kind', MusicVideoShot::PERFORMANCE)
            ->whereNull('characters')
            ->update(['characters' => json_encode([MusicVideoCharacter::SINGER])]);
    }

    public function failed(AiJob $job): void
    {
        MusicVideo::query()
            ->whereKey($job->input['music_video_id'] ?? null)
            ->update([
                'status' => MusicVideo::FAILED,
                'error' => 'The storyboard couldn\'t be drawn. Your credits were refunded. Try again to draw the missing scenes.',
            ]);
    }
}
