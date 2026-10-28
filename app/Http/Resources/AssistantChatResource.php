<?php

namespace App\Http\Resources;

use App\Models\AssistantChat;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin AssistantChat
 */
class AssistantChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'error' => $this->error,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
