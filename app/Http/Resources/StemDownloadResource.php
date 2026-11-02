<?php

namespace App\Http\Resources;

use App\Models\ProjectTrackStem;
use App\Services\Storage\DownloadLink;
use App\Services\Studio\InstrumentAudio;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A download link for a stem of a released track.
 *
 * @mixin ProjectTrackStem
 */
class StemDownloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $title = $this->title ?: $this->meta['name'] ?? 'Stem';
        // An instrument stem's audio is its latest rendering.
        $file = $this->src ?: app(InstrumentAudio::class)->current($this->resource);
        $filename = DownloadLink::filename($title, $file);

        return [
            'id' => $this->id,
            'title' => $title,
            'filename' => $filename,
            'size' => $this->fileSize('src') ?? $this->mediaFileInfo['src']['size'] ?? null,
            'url' => DownloadLink::make('stem_files', $file, $filename),
        ];
    }
}
