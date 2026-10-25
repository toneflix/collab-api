<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

class ProjectCollaboratorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new UserResource($this->user))->resolve($request),
            'id' => $this->id,
            'meta' => $this->meta,
            'role' => $this->role,
            'custom' => $this->meta['custom'] ?? '',
            'userId' => $this->user_id,
            'accepted' => $this->accepted,
            'projectId' => $this->project_id,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
