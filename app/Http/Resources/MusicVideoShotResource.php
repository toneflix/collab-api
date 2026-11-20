<?php

namespace App\Http\Resources;

use App\Models\MusicVideoShot;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * One shot of a music video's storyboard.
 *
 * @mixin MusicVideoShot
 */
class MusicVideoShotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'start' => $this->start,
            'end' => $this->end,
            'kind' => $this->kind,
            'prompt' => $this->prompt,
            'characters' => $this->characters ?? [],
            'keyframeUrl' => app(VideoStorage::class)->keyframeUrl($this->resource),
            'takeId' => $this->take_id,
            'clipUrl' => $this->take && ! $this->stillOnly() ? app(VideoStorage::class)->takeUrl($this->take) : null,
            'offset' => $this->takeOffset(),
            'motion' => $this->motion,
            'rendered' => ! $this->needsClip(),
            'drawing' => $this->drawing,
        ];
    }
}
