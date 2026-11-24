<?php

namespace App\Http\Resources;

use App\Models\TrackCanvas;
use App\Services\Ai\Video\CanvasStorage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A track's canvas: the looping clip to play behind the track and its first
 * frame to show while it loads.
 *
 * @mixin TrackCanvas
 */
class TrackCanvasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        $storage = app(CanvasStorage::class);

        return [
            'id' => $this->id,
            'trackId' => $this->track_id,
            'status' => $this->status,
            'source' => $this->source,
            'brief' => $this->brief,
            'duration' => $this->duration,
            'videoUrl' => $storage->clipUrl($this->resource),
            'posterUrl' => $storage->posterUrl($this->resource),
            'error' => $this->error,
            'createdAt' => $this->created_at,
        ];
    }
}
