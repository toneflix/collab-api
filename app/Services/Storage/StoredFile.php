<?php

namespace App\Services\Storage;

use League\Flysystem\FilesystemException;
use Toneflix\LaravelFileable\Media;

/**
 * Reads the size and mime type of a stored file from its disk.
 */
class StoredFile
{
    /**
     * @return array{name: string, size: int, mime: string|null}|null
     */
    public static function describe(string $collection, string $name): ?array
    {
        if (filter_var($name, FILTER_VALIDATE_URL)) {
            return null;
        }

        $media = new Media;
        $path = $media->mediaInfo($collection, $name)['path'] ?? '';

        try {
            $size = $media->disk->size($path);
            $mime = $media->disk->mimeType($path) ?: null;
        } catch (FilesystemException) {
            return null;
        }

        return [
            'name' => $name,
            'size' => (int) $size,
            'mime' => $mime,
        ];
    }
}
