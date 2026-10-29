<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * An artist as credited on a track: enough to name and link them.
 */
class ArtistCreditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'verified' => (bool) $this->verified,
            'imageUrl' => $this->images['image'],
        ];
    }
}
