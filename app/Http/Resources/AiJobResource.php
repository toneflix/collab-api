<?php

namespace App\Http\Resources;

use App\Models\AiJob;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\MusicVersions;
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
        $model = $modelId ? (MusicModel::find($modelId)?->nameFor($this->input) ?? $modelId) : null;
        $versions = $this->versions();

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
            'versions' => $versions,
            'chosen' => $this->output['chosen'] ?? null,
            'choosing' => $this->output['choosing'] ?? null,
            'createdAt' => $this->created_at,
            'finishedAt' => $this->finished_at,
        ];
    }

    /**
     * The versions waiting for a pick, each with a link that plays it while
     * it can still be picked.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function versions(): ?array
    {
        $versions = $this->output['versions'] ?? null;

        if (! $versions) {
            return null;
        }

        $open = ! isset($this->output['chosen']) && empty($this->output['expired']);
        $links = app(MusicVersions::class);

        return array_map(fn(array $version, int $index) => [
            'index' => $index,
            'title' => $version['title'] ?? null,
            'duration' => $version['duration'] ?? null,
            'image' => $version['meta']['image'] ?? null,
            'url' => $open ? $links->url($this->resource, $index) : null,
        ], $versions, array_keys($versions));
    }
}
