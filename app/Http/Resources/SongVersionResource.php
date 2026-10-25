<?php

namespace App\Http\Resources;

use App\Models\SongVersion;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A saved copy of a song's title and lyrics.
 *
 * @mixin SongVersion
 */
class SongVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => (string) $this->body,
            'kind' => $this->kind,
            'name' => $this->name,
            'createdAt' => $this->created_at,
        ];
    }
}
