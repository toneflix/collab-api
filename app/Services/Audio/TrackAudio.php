<?php

namespace App\Services\Audio;

use App\Models\Track;
use Illuminate\Support\Str;
use Toneflix\LaravelFileable\Media;

/**
 * Copies a released track's audio to a local temporary file, since it may
 * live on a cloud disk. Tracks released from the studio without their own
 * audio can fall back to their project track's preview mix.
 */
class TrackAudio
{
    public function local(Track $track, bool $withPreview = false): ?string
    {
        $file = $track->audio;

        if (! $file && $withPreview) {
            $file = $track->projectTrack()->withoutGlobalScopes()->value('preview');
        }

        return $file ? $this->copy($file) : null;
    }

    protected function copy(string $file): ?string
    {
        $media = new Media;
        $media->setCollectionDisk('music_tracks');
        $path = $media->mediaInfo('music_tracks', $file)['path'] ?? null;

        if (! $path || ! $media->disk->exists($path)) {
            return null;
        }

        $stream = $media->disk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $local = sys_get_temp_dir().'/track-'.Str::random(16).'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'mp3');
        file_put_contents($local, $stream);
        fclose($stream);

        return $local;
    }
}
