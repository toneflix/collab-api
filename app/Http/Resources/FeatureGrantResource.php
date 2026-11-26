<?php

namespace App\Http\Resources;

use App\Models\FeatureGrant;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A plan feature an admin gave one account.
 *
 * @mixin FeatureGrant
 */
class FeatureGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature' => $this->feature->value,
            'title' => $this->feature->title(),
            'reason' => $this->reason,
            'expiresAt' => $this->expires_at,
            'grantedBy' => $this->whenLoaded('grantedBy', fn() => $this->grantedBy?->username),
            'createdAt' => $this->created_at,
        ];
    }
}
