<?php

namespace App\Http\Resources;

use App\Models\AiJob;
use App\Services\Ai\Artwork\ArtworkImages;
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
            'section' => $this->kind === 'edit' ? ['start' => $this->input['start'] ?? null, 'end' => $this->input['end'] ?? null] : null,
            'stemId' => $this->input['stem_id'] ?? null,
            'muted' => $this->output['muted'] ?? null,
            'credits' => $this->credits,
            'stems' => $this->output['stems'] ?? [],
            'error' => $this->error,
            'notice' => $this->output['notice'] ?? null,
            'versions' => $versions,
            'artworkKind' => $this->input['artwork_kind'] ?? null,
            'images' => $this->images(),
            'chosen' => $this->output['chosen'] ?? null,
            'choosing' => $this->output['choosing'] ?? null,
            'createdAt' => $this->created_at,
            'finishedAt' => $this->finished_at,
        ];
    }

    /**
     * The images an artwork job drew, each with a link while they're kept.
     *
     * @return list<array{index: int, width: int, height: int, url: string}>|null
     */
    protected function images(): ?array
    {
        $images = $this->output['images'] ?? null;

        if (! $images || ! empty($this->output['expired'])) {
            return null;
        }

        $links = app(ArtworkImages::class);

        return array_map(fn(array $image, int $index) => [
            'index' => $index,
            'width' => $image['width'],
            'height' => $image['height'],
            'url' => $links->url($this->resource, $index),
        ], $images, array_keys($images));
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
