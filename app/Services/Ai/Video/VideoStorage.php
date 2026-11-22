<?php

namespace App\Services\Ai\Video;

use App\Enums\HttpStatus;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;
use App\Models\User;
use App\Models\VideoCharacter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A music video's files on the uploads disk: its portrait, the stills and
 * takes of its shots, and the finished video. Saved characters keep their
 * sheets in a folder per person.
 */
class VideoStorage
{
    public const DIRECTORY = 'ai/videos';

    public const CHARACTERS = 'ai/characters';

    /** Hours a signed link to a file works */
    protected const LINK_HOURS = 24;

    /**
     * Copy a local file into the video's folder and return its path on the disk.
     */
    public function put(MusicVideo $video, string $local, string $name, string $ext): string
    {
        $file = self::DIRECTORY . "/{$video->id}/{$name}-" . Str::random(8) . ".{$ext}";
        $stream = fopen($local, 'r');

        $this
            ->disk()
            ->writeStream($file, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $file;
    }

    /**
     * Copy a sheet on the disk into the video's folder and return its new path.
     */
    public function copyInto(MusicVideo $video, string $file, string $name): string
    {
        $ext = pathinfo($file, PATHINFO_EXTENSION) ?: 'jpg';
        $copy = self::DIRECTORY . "/{$video->id}/{$name}-" . Str::random(8) . ".{$ext}";

        $this
            ->disk()
            ->copy($file, $copy);

        return $copy;
    }

    /**
     * Keep a character sheet in the person's folder of saved characters,
     * copied from a file on the disk or from a local one.
     */
    public function putCharacter(User $user, string $source, string $ext, bool $local = false): string
    {
        $file = self::CHARACTERS . "/{$user->id}/sheet-" . Str::random(12) . ".{$ext}";
        $disk = $this->disk();

        if (! $local) {
            $disk->copy($source, $file);

            return $file;
        }

        $stream = fopen($source, 'r');

        $disk->writeStream($file, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $file;
    }

    /**
     * Copy a file on the disk to a local temporary file, or null when it's gone.
     */
    public function local(?string $file): ?string
    {
        $disk = $this->disk();

        if (! $file || ! $disk->exists($file)) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'video-');
        $stream = $disk->readStream($file);

        file_put_contents($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    public function delete(?string $file): void
    {
        if ($file) {
            $this
                ->disk()
                ->delete($file);
        }
    }

    /**
     * Remove every file of a video.
     */
    public function forget(MusicVideo $video): void
    {
        $this
            ->disk()
            ->deleteDirectory(self::DIRECTORY . "/{$video->id}");
    }

    /**
     * Delete the folders of videos whose rows are gone, such as videos of
     * deleted tracks or accounts, and the saved character folders of deleted
     * accounts. Folders are checked in batches as the disk lists them.
     *
     * @return int Folders deleted
     */
    public function pruneOrphans(int $batch = 200): int
    {
        return $this->pruneFolders(self::DIRECTORY, MusicVideo::class, $batch)
            + $this->pruneFolders(self::CHARACTERS, User::class, $batch);
    }

    /**
     * @param  class-string<Model>  $model  The model each folder is named after by id
     */
    protected function pruneFolders(string $directory, string $model, int $batch): int
    {
        $disk = $this->disk();
        $ids = [];
        $deleted = 0;

        foreach ($disk->listContents($directory, false) as $item) {
            $id = basename($item->path());

            if (! $item->isDir() || ! ctype_digit($id)) {
                continue;
            }

            $ids[] = (int) $id;

            if (count($ids) >= $batch) {
                $deleted += $this->forgetMissing($directory, $model, $ids);
                $ids = [];
            }
        }

        return $deleted + $this->forgetMissing($directory, $model, $ids);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     */
    protected function forgetMissing(string $directory, string $model, array $ids): int
    {
        if (! $ids) {
            return 0;
        }

        $kept = $model::query()
            ->whereKey($ids)
            ->pluck('id')
            ->all();
        $missing = array_diff($ids, $kept);
        $disk = $this->disk();

        foreach ($missing as $id) {
            $disk->deleteDirectory("{$directory}/{$id}");
        }

        return count($missing);
    }

    /**
     * A link to the finished video: straight from the bucket when the disk
     * can sign one, so players can seek, else through the API.
     */
    public function videoUrl(MusicVideo $video): ?string
    {
        if (! $video->file) {
            return null;
        }

        return $this->signed($video->file, 'ai.videos.file', ['video' => $video->id]);
    }

    public function keyframeUrl(MusicVideoShot $shot): ?string
    {
        if (! $shot->keyframe) {
            return null;
        }

        return $this->signed($shot->keyframe, 'ai.videos.keyframe', ['video' => $shot->music_video_id, 'shot' => $shot->id]);
    }

    public function takeUrl(MusicVideoTake $take): string
    {
        return $this->signed($take->file, 'ai.videos.take', ['video' => $take->music_video_id, 'take' => $take->id]);
    }

    public function sheetUrl(MusicVideoCharacter $character): ?string
    {
        if (! $character->sheet) {
            return null;
        }

        return $this->signed($character->sheet, 'ai.videos.character', ['video' => $character->music_video_id, 'character' => $character->id]);
    }

    public function savedSheetUrl(VideoCharacter $character): string
    {
        return $this->signed($character->sheet, 'ai.characters.sheet', ['character' => $character->id]);
    }

    /**
     * @param  array<string, int>  $parameters
     */
    protected function signed(string $file, string $route, array $parameters): string
    {
        $expires = now()->addHours(self::LINK_HOURS);
        $disk = $this->disk();

        return rescue(
            fn() => $disk->temporaryUrl($file, $expires),
            fn() => URL::temporarySignedRoute($route, $expires, $parameters),
            false,
        );
    }

    /**
     * Send a stored file. Files on a local disk go out with byte range
     * support, so players can seek in them.
     */
    public function stream(?string $file, string $type, ?string $download = null): StreamedResponse|BinaryFileResponse
    {
        $disk = $this->disk();
        $headers = ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600'];

        abort_unless($file && $disk->exists($file), HttpStatus::NOT_FOUND->value, 'That file is gone.');

        if (! $download && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return response()->file($disk->path($file), $headers);
        }

        return $disk->response($file, $download, $headers);
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.default'));
    }
}
