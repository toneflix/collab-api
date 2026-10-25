<?php

namespace App\Http\Resources;

use App\Models\Lyric;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * Lyrics as the player and studio show them. A track without lyrics is
 * an empty object.
 *
 * @mixin Lyric
 */
class LyricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'lines' => $this->lines,
            'synced' => $this->synced(),
            'language' => $this->language,
            'source' => $this->source,
            'explicit' => $this->explicit,
            'updatedAt' => $this->updated_at,
        ];
    }
}
