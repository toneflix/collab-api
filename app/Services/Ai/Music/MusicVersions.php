<?php

namespace App\Services\Ai\Music;

use App\Enums\HttpStatus;
use App\Models\AiJob;
use App\Services\Audio\StemAudio;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The versions of a song a music job made, kept on the uploads disk until
 * the owner picks the one that goes on the track.
 */
class MusicVersions
{
    protected const DIRECTORY = 'ai/versions';

    /** Days versions wait for a pick before they're deleted */
    public const KEEP_DAYS = 7;

    public function __construct(protected StemAudio $audio) {}

    /**
     * Store each song and return what the job's output keeps about them.
     *
     * @param  list<Song>  $songs
     * @return list<array{title: ?string, duration: float, file: string, meta: array<string, mixed>}>
     */
    public function keep(AiJob $job, array $songs): array
    {
        $versions = [];

        foreach ($songs as $index => $song) {
            $path = $song->extension === 'mp3' ? $song->path : $this->audio->mp3($song->path);
            $file = self::DIRECTORY . "/{$job->id}/" . ($index + 1) . '-' . Str::random(8) . '.mp3';

            try {
                $stream = fopen($path, 'r');
                $this
                    ->disk()
                    ->writeStream($file, $stream);
                fclose($stream);

                $versions[] = [
                    'title' => $song->title,
                    'duration' => round($song->duration ?: $this->audio->probe($path)['duration'], 3),
                    'file' => $file,
                    'meta' => $song->meta,
                ];
            } finally {
                if ($path !== $song->path) {
                    @unlink($path);
                }
            }
        }

        return $versions;
    }

    /**
     * A local copy of a version, checking the job is still waiting for a pick.
     */
    public function take(AiJob $job, int $index): Song
    {
        $version = $this->find($job, $index);
        $path = sys_get_temp_dir() . '/version-' . Str::random(16) . '.mp3';

        file_put_contents($path, $this->disk()->readStream($version['file']));

        return new Song($path, 'mp3', $version['title'], (float) $version['duration'], (array) ($version['meta'] ?? []));
    }

    /**
     * The version, or a validation error when it can't be picked.
     *
     * @return array{title: ?string, duration: float, file: string, meta?: array<string, mixed>}
     */
    public function find(AiJob $job, int $index): array
    {
        $version = $job->output['versions'][$index] ?? null;

        if (! $version || isset($job->output['chosen']) || ! empty($job->output['expired']) || ! $this->disk()->exists($version['file'])) {
            throw ValidationException::withMessages(['version' => 'That version can\'t be added any more.']);
        }

        return $version;
    }

    /**
     * Delete the job's version files.
     */
    public function forget(AiJob $job): void
    {
        $this
            ->disk()
            ->deleteDirectory(self::DIRECTORY . "/{$job->id}");
    }

    /**
     * A link that plays a version for a few hours without signing in.
     */
    public function url(AiJob $job, int $index): string
    {
        return URL::temporarySignedRoute('ai.jobs.versions.audio', now()->addHours(6), ['job' => $job->id, 'version' => $index]);
    }

    public function stream(AiJob $job, int $index): StreamedResponse
    {
        $file = $job->output['versions'][$index]['file'] ?? null;

        abort_unless($file && $this->disk()->exists($file), HttpStatus::NOT_FOUND->value, 'That version is gone.');

        return $this
            ->disk()
            ->response($file, null, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=21600']);
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.default'));
    }
}
