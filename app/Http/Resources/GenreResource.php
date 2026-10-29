<?php

namespace App\Http\Resources;

use App\Models\Genre;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin Genre
 */
class GenreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'imageUrl' => $this->coverUrl(),
            'tracksCount' => $this->whenCounted('tracks'),
            'releasesCount' => $this->whenCounted('releases'),
            'fansCount' => $this->whenCounted('fans'),
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
