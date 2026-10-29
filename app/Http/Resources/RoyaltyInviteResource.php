<?php

namespace App\Http\Resources;

use App\Models\RoyaltyInvite;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin RoyaltyInvite
 */
class RoyaltyInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $forAdmin = $request->routeIs('admin.*');

        return [
            'id' => $this->when($forAdmin, $this->id),
            'email' => $this->email,
            'name' => $this->name,
            'rate' => $this->rate,
            'months' => $this->months,
            'code' => $this->code,
            'note' => $this->when($forAdmin, $this->note),
            'status' => $this->status(),
            'hasAccount' => (bool) $this->user_id,
            'invitedBy' => $this->when(
                $forAdmin && $this->relationLoaded('invitedBy') && $this->invitedBy,
                fn() => ['id' => $this->invitedBy->id, 'username' => $this->invitedBy->username, 'fullname' => $this->invitedBy->fullname],
            ),
            'expiresAt' => $this->expires_at,
            'acceptedAt' => $this->accepted_at,
            'cancelledAt' => $this->cancelled_at,
            'createdAt' => $this->created_at,
        ];
    }
}
