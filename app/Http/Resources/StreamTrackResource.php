<?php

namespace App\Http\Resources;

use App\Models\Track;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A creator's track with its stream stats for one month.
 *
 * @mixin Track
 */
class StreamTrackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'imageUrl' => $this->files['image'] ?? null,
            'artist' => $this->artist()->withoutGlobalScopes()->value('username'),
            'album' => $this->release?->title,
            'origin' => $this->origin?->value,
            'plays' => (int) $this->stat_plays,
            'paid' => (int) $this->stat_paid,
            'held' => (int) $this->stat_held,
            'earned' => (float) $this->stat_earned,
        ];
    }
}
