<?php

namespace App\Http\Resources;

use App\Models\AiJob;
use App\Services\Ai\Music\MusicModel;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

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
        $modelId = $this->input['model'] ?? null;
        $model = $modelId ? (MusicModel::find($modelId)?->name() ?? $modelId) : null;

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'trackId' => $this->project_track_id,
            'prompt' => $this->input['prompt'] ?? null,
            'model' => $model,
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
