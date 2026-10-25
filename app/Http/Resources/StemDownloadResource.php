<?php

namespace App\Http\Resources;

use App\Models\ProjectTrackStem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A download link for a stem of a released track.
 *
 * @mixin ProjectTrackStem
 */
class StemDownloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title ?: $this->meta['name'] ?? 'Stem',
            'size' => $this->mediaFileInfo['src']['size'] ?? null,
            'url' => $this->mediaFileInfo['src']['dynamicLink'] ?? null,
        ];
    }
}
