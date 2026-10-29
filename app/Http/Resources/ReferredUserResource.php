<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * Someone the viewer invited, and what their payments earned the viewer.
 *
 * @mixin User
 */
class ReferredUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'fullname' => $this->fullname,
            'imageUrl' => $this->images['image'] ?? null,
            'joinedAt' => $this->referred_at,
            'earned' => round((float) ($this->referral_earned ?? 0), 2),
        ];
    }
}
