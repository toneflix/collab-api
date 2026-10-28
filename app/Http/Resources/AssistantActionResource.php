<?php

namespace App\Http\Resources;

use App\Models\AssistantAction;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin AssistantAction
 */
class AssistantActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'summary' => $this->summary,
            'asks' => $this->asks ?? [],
            'status' => $this->isPending() && $this->hasExpired() ? AssistantAction::EXPIRED : $this->status,
            'error' => $this->error,
            'expiresAt' => $this->created_at?->copy()->addMinutes(AssistantAction::EXPIRES_AFTER),
            'decidedAt' => $this->decided_at,
            'createdAt' => $this->created_at,
        ];
    }
}
