<?php

namespace App\Http\Resources;

use App\Models\RoyaltyContract;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin RoyaltyContract
 */
class RoyaltyContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate' => $this->rate,
            'months' => $this->months,
            'note' => $this->note,
            'active' => $this->isActive(),
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'invited' => $this->when(isset($this->invited_count), fn() => (int) $this->invited_count),
            'earned' => $this->when(isset($this->royalty_earned), fn() => round((float) $this->royalty_earned, 2)),
            'user' => $this->whenLoaded(
                'user',
                fn() => [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'fullname' => $this->user->fullname,
                    'email' => $this->user->email,
                    'code' => $this->user->referral_code,
                    'imageUrl' => $this->user->images['image'] ?? null,
                ],
            ),
        ];
    }
}
