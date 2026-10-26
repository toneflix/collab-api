<?php

namespace App\Services\Composer;

use App\Models\Song;
use Illuminate\Http\UploadedFile;
use Toneflix\LaravelFileable\Media;

/**
 * Sets and removes a song's artwork, from an upload or a made image.
 */
class SongArtwork
{
    public const COLLECTION = 'artwork';

    public function set(Song $song, UploadedFile $file): Song
    {
        $song->image = (new Media($song->disk))->save(self::COLLECTION, $file, $song->image);
        $song->saveQuietly();

        return $song;
    }

    public function remove(Song $song): Song
    {
        if ($song->image) {
            $song->removeFile('image', self::COLLECTION);
            $song->image = null;
            $song->saveQuietly();
        }

        return $song;
    }
}
