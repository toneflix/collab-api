<?php

namespace App\Http\Resources;

use App\Models\ProjectTrack;
use App\Models\Track;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * The tempo and key of a project track or released track.
 *
 * @mixin Track|ProjectTrack
 */
class AudioTagsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->audioTags();
    }
}
