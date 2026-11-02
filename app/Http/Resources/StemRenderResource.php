<?php

namespace App\Http\Resources;

use App\Models\ProjectTrackStem;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * One instrument stem as the studio's render page mixes it: alone, with its
 * effects, at full volume and centred, like an audio stem's own file.
 *
 * @mixin ProjectTrackStem
 */
class StemRenderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $track = $this->track;

        return [
            'id' => $track->id,
            'title' => $this->title,
            'bpm' => (float) ($track->meta['bpm'] ?? 120),
            'timeSignature' => (string) ($track->meta['timeSignature'] ?? '4/4'),
            'mixVolume' => 1,
            'masterEffects' => '',
            'stems' => [
                [
                    'id' => $this->id,
                    'title' => $this->title,
                    'src' => null,
                    'size' => 0,
                    'meta' => collect($this->meta)
                        ->except(['src', 'gain', 'stereoPan', 'muted', 'soloed', 'automation', 'bounce'])
                        ->all(),
                ],
            ],
        ];
    }
}
