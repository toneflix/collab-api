<?php

namespace App\Http\Resources;

use App\Services\Catalog\ContentCatalog;
use App\Services\Moderation\ModerationQueue;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A piece of content as the admin content pages show and edit it: the same
 * summary moderation shows, plus the values the edit form needs.
 */
class AdminContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $owner = $this->resource->relationLoaded('catalogOwner')
            ? $this->resource->getRelation('catalogOwner')
            : null;

        return [
            ...app(ModerationQueue::class)->describe($this->resource, $owner),
            'fields' => app(ContentCatalog::class)
                ->typeFor($this->resource)
                ->fields($this->resource),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
