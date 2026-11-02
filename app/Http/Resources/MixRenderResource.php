<?php

namespace App\Http\Resources;

use App\Models\ProjectTrackStem;
use App\Services\Studio\MixPreview;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * What the studio's render page needs to mix a track: its tempo, master
 * settings and every stem with a link to its audio.
 */
class MixRenderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'bpm' => (float) ($this->meta['bpm'] ?? 120),
            'timeSignature' => (string) ($this->meta['timeSignature'] ?? '4/4'),
            'mixVolume' => MixPreview::masterVolume($this->resource),
            'masterEffects' => (string) ($this->meta['masterEffects'] ?? ''),
            'stems' => $this->stems
                ->map(fn(ProjectTrackStem $stem) => [
                    'id' => $stem->id,
                    'title' => $stem->title,
                    'src' => $stem->mediaFileInfo['src']['dynamicLink'] ?? null,
                    'size' => $stem->fileSize('src') ?? $stem->mediaFileInfo['src']['size'] ?? 0,
                    'meta' => collect($stem->meta)
                        ->except('src')
                        ->all(),
                ])
                ->values(),
        ];
    }
}
