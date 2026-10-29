<?php

namespace App\Http\Resources;

use App\Services\Releases\TrackCredits;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin TrackCredits
 */
class TrackCreditsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'artists' => $this->artists(),
            'collaborators' => $this->collaborators(),
            'details' => $this->details(),
        ];
    }
}
