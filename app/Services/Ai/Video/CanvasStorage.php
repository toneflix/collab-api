<?php

namespace App\Services\Ai\Video;

use App\Models\TrackCanvas;

/**
 * Track canvases' clips and posters on the uploads disk, one folder per canvas.
 */
class CanvasStorage extends VideoStorage
{
    public const DIRECTORY = 'ai/canvases';

    public function clipUrl(TrackCanvas $canvas): ?string
    {
        if (! $canvas->file) {
            return null;
        }

        return $this->signed($canvas->file, 'ai.canvases.clip', ['canvas' => $canvas->id]);
    }

    public function posterUrl(TrackCanvas $canvas): ?string
    {
        if (! $canvas->poster) {
            return null;
        }

        return $this->signed($canvas->poster, 'ai.canvases.poster', ['canvas' => $canvas->id]);
    }

    /**
     * Delete the folders of canvases whose rows are gone.
     *
     * @return int Folders deleted
     */
    public function pruneOrphans(int $batch = 200): int
    {
        return $this->pruneFolders(self::DIRECTORY, TrackCanvas::class, $batch);
    }
}
