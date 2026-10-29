<?php

namespace App\Http\Resources;

use App\Models\StreamCheck;
use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin StreamCheck
 */
class StreamCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $person = fn(?User $user) => $user ? [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->fullname,
            'imageUrl' => $user->images['image'] ?? null,
            'joinedAt' => $user->created_at,
        ] : null;

        return [
            'id' => $this->id,
            'month' => $this->month->format('Y-m'),
            'plays' => $this->plays,
            'share' => $this->share,
            'status' => $this->status,
            'topUp' => $this->top_up,
            'listener' => $person($this->user),
            'owner' => $person($this->owner),
            'reviewer' => $this->whenLoaded('reviewer', fn() => $this->reviewer?->fullname),
            'reviewedAt' => $this->reviewed_at,
            'tracks' => $this->whenLoaded('tracks'),
            'createdAt' => $this->created_at,
        ];
    }
}
