<?php

namespace App\Http\Resources;

use App\Models\Takedown;
use App\Services\Moderation\ModerationQueue;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin Takedown
 */
class TakedownResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => app(ModerationQueue::class)->item($this->model_type, $this->model_id),
            'reason' => $this->reason,
            'note' => $this->note,
            'status' => $this->status,
            'appeal' => $this->appeal,
            'appealedAt' => $this->appealed_at,
            'canAppeal' => $this->status === 'active' && ! $this->appealed_at,
            'resolution' => $this->resolution,
            'resolvedAt' => $this->resolved_at,
            'moderator' => $this->whenLoaded('moderator', fn() => $this->moderator ? [
                'id' => $this->moderator->id,
                'name' => $this->moderator->fullname,
            ] : null),
            'createdAt' => $this->created_at,
        ];
    }
}
