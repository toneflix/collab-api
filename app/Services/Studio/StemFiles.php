<?php

namespace App\Services\Studio;

use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Toneflix\LaravelFileable\Initiator;
use Toneflix\LaravelFileable\Media;

/**
 * Reads and writes stem audio on the stems disk, for stems made outside an
 * upload, like the ones AI tools create.
 */
class StemFiles
{
    /**
     * Copy a local file onto the stems disk and return its new file name.
     */
    public function put(string $path, string $ext = 'mp3'): string
    {
        $media = $this->media();
        $name = Initiator::generateStringFromPattern(config('toneflix-fileable.file_name_pattern', '000000000-000000000')) . '.' . $ext;

        $stream = fopen($path, 'r');
        $media->disk->writeStream($this->directory($media) . $name, $stream);
        fclose($stream);

        return $name;
    }

    /**
     * Copy a stem's audio to a local temporary file, as it may be on a cloud disk.
     */
    public function local(ProjectTrackStem $stem): ?string
    {
        $media = $this->media();
        $path = $this->directory($media) . $stem->src;

        if (! $stem->src || ! $media->disk->exists($path)) {
            return null;
        }

        $stream = $media->disk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $local = sys_get_temp_dir() . '/stem-' . Str::random(16) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'mp3');
        file_put_contents($local, $stream);
        fclose($stream);

        return $local;
    }

    /**
     * Add a new stem to a track from a local audio file.
     *
     * @param  array<string, mixed>  $meta
     */
    public function addStem(ProjectTrack $track, User $user, string $path, string $title, array $meta = [], string $ext = 'mp3'): ProjectTrackStem
    {
        $stem = $track
            ->stems()
            ->make([
                'title' => str($title)->limit(180, '')->toString(),
                'user_id' => $user->id,
            ]);
        $stem->src = $this->put($path, $ext);
        $stem->meta = ['gain' => 1, 'start' => 0, ...$meta];
        $stem->save();

        return $stem->refresh();
    }

    protected function media(): Media
    {
        $media = new Media;
        $media->setCollectionDisk('stem_files');

        return $media;
    }

    protected function directory(Media $media): string
    {
        return $media->getPrefix('stem_files') . Arr::get($media->namespaces, 'stem_files.path');
    }
}
