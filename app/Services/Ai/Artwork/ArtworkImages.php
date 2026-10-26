<?php

namespace App\Services\Ai\Artwork;

use App\Enums\HttpStatus;
use App\Models\AiJob;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The images an artwork job drew, cropped to their shape and kept on the
 * uploads disk until they expire. People save the one they like with the
 * form it was drawn for.
 */
class ArtworkImages
{
    protected const DIRECTORY = 'ai/artwork';

    /** Days images are kept before they're deleted */
    public const KEEP_DAYS = 7;

    /** Longest side of a stored image, in pixels */
    protected const MAX_SIDE = 1600;

    /**
     * Crop and store each image, and return what the job's output keeps
     * about them.
     *
     * @param  list<string>  $paths
     * @param  array{0: int, 1: int}  $ratio
     * @return list<array{file: string, width: int, height: int}>
     */
    public function keep(AiJob $job, array $paths, array $ratio): array
    {
        $images = [];

        foreach ($paths as $index => $path) {
            [$jpeg, $width, $height] = $this->crop($path, $ratio);
            $file = self::DIRECTORY . "/{$job->id}/" . ($index + 1) . '-' . Str::random(8) . '.jpg';

            $this
                ->disk()
                ->put($file, $jpeg);

            $images[] = ['file' => $file, 'width' => $width, 'height' => $height];
        }

        return $images;
    }

    /**
     * Delete the job's images.
     */
    public function forget(AiJob $job): void
    {
        $this
            ->disk()
            ->deleteDirectory(self::DIRECTORY . "/{$job->id}");
    }

    /**
     * A link that shows an image for a day without signing in.
     */
    public function url(AiJob $job, int $index): string
    {
        return URL::temporarySignedRoute('ai.jobs.artwork.image', now()->addDay(), ['job' => $job->id, 'image' => $index]);
    }

    public function stream(AiJob $job, int $index): StreamedResponse
    {
        $file = $job->output['images'][$index]['file'] ?? null;

        abort_unless($file && $this->disk()->exists($file), HttpStatus::NOT_FOUND->value, 'That image is gone.');

        return $this
            ->disk()
            ->response($file, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * Cut the middle of an image to the ratio, shrink it to MAX_SIDE and
     * encode it as a JPEG.
     *
     * @param  array{0: int, 1: int}  $ratio
     * @return array{0: string, 1: int, 2: int}
     */
    protected function crop(string $path, array $ratio): array
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));

        if (! $source) {
            throw new RuntimeException('The model returned an unreadable image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $wanted = $ratio[0] / $ratio[1];

        [$cropWidth, $cropHeight] = $width / $height > $wanted
            ? [(int) round($height * $wanted), $height]
            : [$width, (int) round($width / $wanted)];

        $scale = min(1, self::MAX_SIDE / max($cropWidth, $cropHeight));
        $outWidth = (int) round($cropWidth * $scale);
        $outHeight = (int) round($cropHeight * $scale);

        $out = imagecreatetruecolor($outWidth, $outHeight);
        imagecopyresampled(
            $out,
            $source,
            0,
            0,
            (int) (($width - $cropWidth) / 2),
            (int) (($height - $cropHeight) / 2),
            $outWidth,
            $outHeight,
            $cropWidth,
            $cropHeight,
        );

        ob_start();
        imagejpeg($out, null, 90);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($out);

        return [$jpeg, $outWidth, $outHeight];
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.default'));
    }
}
