<?php

namespace App\Http\Resources;

use App\Models\AssistantMessage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin AssistantMessage
 */
class AssistantMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'tool' => $this->data['tool'] ?? null,
            'input' => $this->data['input'] ?? null,
            'error' => $this->data['error'] ?? null,
            'action' => $this->whenLoaded('action', fn() => $this->action ? new AssistantActionResource($this->action) : null),
            'createdAt' => $this->created_at,
        ];
    }
}
