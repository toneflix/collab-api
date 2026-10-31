<?php

namespace App\Http\Resources;

use App\Models\MusicVideo;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A track's finished music video, as listeners see it: what to play and its
 * first still to show while it loads.
 *
 * @mixin MusicVideo
 */
class TrackVideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        $storage = app(VideoStorage::class);
        $first = $this->whenLoaded('shots', fn() => $this->shots->first());

        return [
            'id' => $this->id,
            'trackId' => $this->track_id,
            'ratio' => $this->ratio,
            'duration' => $this->duration,
            'videoUrl' => $storage->videoUrl($this->resource),
            'posterUrl' => $first ? $storage->keyframeUrl($first) : null,
        ];
    }
}
