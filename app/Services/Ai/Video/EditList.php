<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Services\Video\VideoPart;
use RuntimeException;
use Throwable;

/**
 * What a music video is cut from: for each shot in order, the take or still
 * it plays, where in it, and for how long.
 */
class EditList
{
    public function __construct(protected VideoStorage $storage) {}

    /**
     * The edit list as stored with the finished video: each shot as it was
     * stitched, so unsaved changes can be put back.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(MusicVideo $video): array
    {
        return $video->shots
            ->map(fn(MusicVideoShot $shot) => [
                'shot' => $shot->id,
                'take' => $shot->take_id,
                'offset' => $shot->offset,
                'length' => $shot->length(),
                'motion' => $shot->motion,
                'start' => $shot->start,
                'end' => $shot->end,
                'kind' => $shot->kind,
                'prompt' => $shot->prompt,
                'keyframe' => $shot->keyframe,
                'characters' => $shot->characters ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * The parts to stitch, each copied to a local file the caller removes.
     *
     * @return list<VideoPart>
     */
    public function parts(MusicVideo $video): array
    {
        $parts = [];

        try {
            foreach ($video->shots as $shot) {
                $parts[] = $this->part($shot);
            }
        } catch (Throwable $e) {
            array_map(fn(VideoPart $part) => @unlink($part->path), $parts);

            throw $e;
        }

        return $parts;
    }

    protected function part(MusicVideoShot $shot): VideoPart
    {
        if ($shot->stillOnly()) {
            $still = $this->storage->local($shot->keyframe) ?? throw new RuntimeException("Shot {$shot->position} has no still.");

            return VideoPart::still($still, $shot->length(), $shot->motion);
        }

        $clip = $this->storage->local($shot->take?->file) ?? throw new RuntimeException("Shot {$shot->position} has no clip.");

        return VideoPart::clip($clip, $shot->length(), $shot->takeOffset());
    }
}
