<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * An account content can be added for.
 *
 * @mixin User
 */
class ContentOwnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->fullname,
            'username' => $this->username,
            'imageUrl' => $this->images['image'] ?? null,
        ];
    }
}
