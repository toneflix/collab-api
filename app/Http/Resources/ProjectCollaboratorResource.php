<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'user_id' => $this->user_id,
            'accepted' => $this->accepted,
            'project_id' => $this->project_id,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
