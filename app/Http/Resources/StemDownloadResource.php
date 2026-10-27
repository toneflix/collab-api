<?php

namespace App\Http\Resources;

use App\Models\ProjectTrackStem;
use App\Services\Storage\DownloadLink;
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
        $filename = DownloadLink::filename($title, $this->src);

        return [
            'id' => $this->id,
            'title' => $title,
            'filename' => $filename,
            'size' => $this->fileSize('src') ?? $this->mediaFileInfo['src']['size'] ?? null,
            'url' => DownloadLink::make('stem_files', $this->src, $filename),
        ];
    }
}
