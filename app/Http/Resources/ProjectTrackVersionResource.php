<?php

namespace App\Http\Resources;

use App\Models\ProjectTrackVersion;
use App\Services\Conversations\Conversations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectTrackVersion
 */
class ProjectTrackVersionResource extends JsonResource
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
            'action' => $this->action,
            'summary' => $this->summary,
            'changes' => $this->changes ?? [],
            'user' => Conversations::person($this->user),
            'stems' => count($this->stem_ids ?? []),
            'bpm' => $this->snapshot['bpm'] ?? null,
            'timeSignature' => $this->snapshot['timeSignature'] ?? null,
            'restoredFrom' => $this->restoredFrom ? [
                'id' => $this->restoredFrom->id,
                'createdAt' => $this->restoredFrom->created_at,
            ] : null,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
