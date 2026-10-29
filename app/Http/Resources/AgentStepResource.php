<?php

namespace App\Http\Resources;

use App\Models\AgentStep;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * One step of a content agent run and the ids of what it made.
 *
 * @mixin AgentStep
 */
class AgentStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $output = $this->output ?? [];

        return [
            'id' => $this->id,
            'key' => $this->key,
            'kind' => $this->kind,
            'status' => $this->status,
            'title' => $this->input['title'] ?? $this->input['name'] ?? null,
            'instrumental' => $this->kind === 'music' ? (bool) ($this->input['instrumental'] ?? false) : null,
            'dependsOn' => $this->depends_on ?? [],
            'made' => array_filter([
                'artistId' => $output['artist_id'] ?? null,
                'projectId' => $output['project_id'] ?? null,
                'songId' => $output['song_id'] ?? null,
                'trackId' => $output['project_track_id'] ?? $this->input['track_id'] ?? null,
                'aiJobId' => $output['ai_job_id'] ?? null,
                'releaseId' => $output['release_id'] ?? null,
            ]),
            'published' => $output['published'] ?? null,
            'synced' => $output['synced'] ?? null,
            'notice' => $output['missed'] ?? null,
            'error' => $this->error,
            'failure' => $this->when($request->is('api/admin/*'), $output['failure'] ?? null),
            'startedAt' => $this->started_at,
            'finishedAt' => $this->finished_at,
        ];
    }
}
