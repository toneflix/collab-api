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
        return $this->localFile($stem->src);
    }

    /**
     * Copy a file on the stems disk to a local temporary file.
     */
    public function localFile(?string $name): ?string
    {
        $media = $this->media();
        $path = $this->directory($media) . $name;

        if (! $name || ! $media->disk->exists($path)) {
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
     * Remove a file from the stems disk.
     */
    public function delete(?string $name): void
    {
        $media = $this->media();

        if ($name && $media->disk->exists($this->directory($media) . $name)) {
            $media->disk->delete($this->directory($media) . $name);
        }
    }

    /**
     * Save a stem's printed audio: the stem as heard through its plugins,
     * which FFmpeg can't run. The mix uses it in place of the stem.
     */
    public function print(ProjectTrackStem $stem, string $path, string $ext, float $start, string $key): ProjectTrackStem
    {
        $old = $stem->meta['print']['file'] ?? null;
        $name = $this->put($path, $ext);
        $meta = collect($stem->meta)
            ->except('src')
            ->all();

        $stem->meta = [
            ...$meta,
            'print' => ['file' => $name, 'start' => $start, 'key' => $key],
        ];
        $stem->saveQuietly();

        $this->delete(is_string($old) ? $old : null);

        return $stem;
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

    /**
     * Copy a stem, audio included, onto the same track.
     *
     * @param  array<string, mixed>  $meta  Settings for the copy, over the original's
     */
    public function duplicate(ProjectTrackStem $stem, User $user, array $meta = []): ProjectTrackStem
    {
        $media = $this->media();
        $directory = $this->directory($media);
        $name = Initiator::generateStringFromPattern(config('toneflix-fileable.file_name_pattern', '000000000-000000000'))
            . '.' . (pathinfo((string) $stem->src, PATHINFO_EXTENSION) ?: 'mp3');

        abort_unless($stem->src && $media->disk->exists($directory . $stem->src), 404, 'The stem has no audio to copy.');

        $media->disk->copy($directory . $stem->src, $directory . $name);

        $copy = $stem->replicate(['src']);
        $copy->user_id = $user->id;
        $copy->title = str(($stem->title ?: 'Stem') . ' copy')->limit(180, '')->toString();
        $copy->src = $name;
        $copy->meta = [...collect($stem->meta)->except(['src', 'print'])->all(), ...$meta];
        $copy->saveQuietly();

        return $copy->refresh();
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
