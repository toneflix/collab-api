<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Safety
 */
class SafetyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'reason' => $this->reason,
            'details' => $this->details,
            'claim' => $this->data,
            'status' => $this->status,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
                'username' => $this->user->username,
            ] : null),
            'createdAt' => $this->created_at,
            'reviewedAt' => $this->reviewed_at,
        ];
    }
}
