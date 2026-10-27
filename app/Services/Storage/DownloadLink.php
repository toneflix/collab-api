<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Toneflix\LaravelFileable\Media;

/**
 * Links that save a stored file instead of playing it. The file links from
 * fileable send audio inline, so browsers and the app would play it.
 */
class DownloadLink
{
    /** How long a download link works. */
    public const TTL_MINUTES = 60;

    /** The collections a download link can point into. */
    public const COLLECTIONS = ['music_tracks', 'stem_files'];

    /**
     * A link that downloads the file as $filename. Remote disks (S3) sign a
     * bucket link that sends the download header; files on this server go
     * through the signed files.download route.
     */
    public static function make(string $collection, ?string $name, string $filename): ?string
    {
        if (! $name) {
            return null;
        }

        // Files hosted elsewhere can't be given the header
        if (filter_var($name, FILTER_VALIDATE_URL)) {
            return $name;
        }

        $media = new Media;
        $path = $media->mediaInfo($collection, $name)['path'] ?? '';
        $expires = now()->addMinutes(self::TTL_MINUTES);

        if (! $media->isLocal()) {
            try {
                return $media->disk->temporaryUrl(
                    ltrim($path, '/'),
                    $expires,
                    ['ResponseContentDisposition' => self::disposition($filename)],
                );
            } catch (\RuntimeException) {
                // The disk can't sign links, so the API streams the file instead
            }
        }

        return URL::temporarySignedRoute('files.download', $expires, [
            'collection' => $collection,
            'name' => $name,
            'filename' => $filename,
        ]);
    }

    /**
     * Streams a stored file as an attachment, for the signed route.
     */
    public static function stream(string $collection, string $name, string $filename): StreamedResponse
    {
        $media = new Media;
        $path = $media->mediaInfo($collection, $name)['path'] ?? '';

        abort_unless($path !== '' && $media->disk->exists(ltrim($path, '/')), 404, 'This file is no longer available.');

        return $media->disk->download(ltrim($path, '/'), $filename, [
            'Content-Disposition' => self::disposition($filename),
        ]);
    }

    /**
     * A file name for a download: the title made safe, with the stored file's extension.
     */
    public static function filename(string $title, ?string $stored, string $fallbackExtension = 'mp3'): string
    {
        $extension = pathinfo((string) parse_url((string) $stored, PHP_URL_PATH), PATHINFO_EXTENSION) ?: $fallbackExtension;
        $base = Str::slug($title) ?: 'download';

        return "{$base}.{$extension}";
    }

    /**
     * The Content-Disposition value, with a plain ASCII name for older clients.
     */
    protected static function disposition(string $filename): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            Str::ascii(str_replace(['/', '\\', '%'], '-', $filename)) ?: 'download',
        );
    }
}
