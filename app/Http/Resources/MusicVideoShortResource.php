<?php

namespace App\Http\Resources;

use App\Models\MusicVideoShort;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A vertical short of a music video, with links to play and download it.
 *
 * @mixin MusicVideoShort
 */
class MusicVideoShortResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $storage = app(VideoStorage::class);

        return [
            'id' => $this->id,
            'videoId' => $this->music_video_id,
            'status' => $this->status,
            'start' => $this->start,
            'end' => $this->end,
            'length' => $this->length(),
            'focus' => $this->focus,
            'fileUrl' => $storage->shortUrl($this->resource),
            'downloadUrl' => $storage->shortUrl($this->resource, true),
            'error' => $this->error,
            'createdAt' => $this->created_at,
        ];
    }
}
