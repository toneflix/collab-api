<?php

namespace App\Http\Resources;

use App\Models\Sound;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin Sound
 */
class SoundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'bpm' => $this->bpm,
            'key' => $this->key,
            'duration' => $this->duration,
            'author' => $this->author,
            'license' => $this->license,
            'source' => $this->source,
            'sourceUrl' => $this->source_url,
            'quality' => $this->quality,
            'size' => $this->size,
            'waveform' => $this->waveform ?? [],
            'url' => $this->url(),
            'isActive' => $this->is_active,
            'takenDown' => $this->taken_down_at !== null,
            'mine' => $this->user_id !== null,
            'createdAt' => $this->created_at,
        ];
    }
}
