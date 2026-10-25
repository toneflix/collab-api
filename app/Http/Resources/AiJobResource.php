<?php

namespace App\Http\Resources;

use App\Models\AiJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An AI job as the studio shows it.
 *
 * @mixin AiJob
 */
class AiJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'trackId' => $this->project_track_id,
            'prompt' => $this->input['prompt'] ?? null,
            'seconds' => $this->input['seconds'] ?? null,
            'split' => (bool) ($this->input['split'] ?? $this->kind === 'stems'),
            'title' => $this->input['title'] ?? null,
            'lines' => $this->output['lines'] ?? null,
            'credits' => $this->credits,
            'stems' => $this->output['stems'] ?? [],
            'error' => $this->error,
            'notice' => $this->output['notice'] ?? null,
            'createdAt' => $this->created_at,
            'finishedAt' => $this->finished_at,
        ];
    }
}
