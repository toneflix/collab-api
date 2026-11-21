<?php

namespace App\Services\Ai\Video;

use App\Enums\VideoCandidate;
use App\Models\Track;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Ai\Stems\DemucsSplitter;
use App\Services\Audio\StemAudio;
use App\Services\Audio\TrackAudio;
use App\Services\Lyrics\Lyrics;
use App\Services\Video\VideoFiles;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Renders one window of a released song through several video models, so
 * admins can compare them before the music video maker picks its defaults.
 * Each clip is saved with the song's own audio under it.
 */
class VideoTrial
{
    public const DIRECTORY = 'ai/video-trials';

    /** Seconds a single model run may take */
    protected const TIMEOUT = 1200;

    public function __construct(
        protected TrackAudio $tracks,
        protected StemAudio $audio,
        protected DemucsSplitter $splitter,
        protected ArtworkImages $images,
        protected VideoFiles $video,
        protected Lyrics $lyrics,
    ) {}

    /**
     * Cut the window from the song, crop the still and upload what the
     * models read. The vocal is split out only when a singing model needs it.
     * The window starts at the first sung line when no start is given.
     *
     * @param  array{0: int, 1: int}  $ratio
     */
    public function prepare(Track $track, ?float $from, float $seconds, ?string $image, array $ratio, bool $vocals): TrialWindow
    {
        $master = $this->tracks->local($track, true) ?? throw new RuntimeException('That track has no audio.');

        try {
            $duration = $this->audio->probe($master)['duration'];
            $from ??= $this->firstLine($track);
            $from = max(0, min($from, $duration - $seconds));
            $seconds = min($seconds, $duration - $from);

            $song = $this->audio->cut($master, $from, $from + $seconds);
        } finally {
            @unlink($master);
        }

        $files = [$song];

        try {
            $client = ReplicateClient::make();
            $source = $this->still($track, $image);
            $files[] = $source;

            $still = $this->images
                ->cropped($source, $ratio)
                ->getRealPath();
            $files[] = $still;

            $vocal = $vocals ? $this->vocals($song) : null;
            $files = array_values(array_filter([...$files, $vocal]));

            $imageUrl = $client->upload($still, 'image/jpeg');
            $vocalUrl = $vocal ? $client->upload($vocal, 'audio/wav') : null;

            return new TrialWindow(
                song: $song,
                from: $from,
                seconds: $seconds,
                image: $imageUrl,
                vocals: $vocalUrl,
                files: $files,
            );
        } catch (Throwable $e) {
            array_map(fn(string $file) => @unlink($file), $files);

            throw $e;
        }
    }

    /**
     * Run one model over the window and save its clip with the song under it.
     * A dubbing model first gets its source clip. Returns the clip's path on
     * the uploads disk.
     */
    public function render(VideoCandidate $candidate, TrialWindow $window, string $prompt, string $folder): string
    {
        $client = ReplicateClient::make();
        $source = $candidate->source();
        $video = $source ? $this->run($client, $source, $window, $prompt) : null;

        $output = $this->run($client, $candidate, $window, $prompt, $video);
        $clip = $client->download($output);

        try {
            $saved = $this->video->withAudio($clip, $window->song);
        } finally {
            @unlink($clip);
        }

        $file = "{$folder}/{$candidate->value}.mp4";
        $stream = fopen($saved, 'r');

        $this
            ->disk()
            ->writeStream($file, $stream);

        fclose($stream);
        @unlink($saved);

        return $file;
    }

    /**
     * A link to a saved clip that works for a week.
     */
    public function url(string $file): string
    {
        $disk = $this->disk();

        return rescue(
            fn() => $disk->temporaryUrl($file, now()->addWeek()),
            fn() => $disk->url($file),
            false,
        );
    }

    /**
     * Run one model over the window and return the link to its video.
     */
    protected function run(ReplicateClient $client, VideoCandidate $candidate, TrialWindow $window, string $prompt, ?string $video = null): string
    {
        $input = $candidate->input($window->image, $window->vocals, $prompt, $window->seconds, $video);
        $prediction = $client->run($candidate->model(), $input, self::TIMEOUT);

        $output = is_array($prediction['output']) ? reset($prediction['output']) : $prediction['output'];

        if (! ReplicateClient::isFile($output)) {
            throw new ReplicateException("{$candidate->model()} returned no video.");
        }

        return $output;
    }

    protected function firstLine(Track $track): float
    {
        $sheet = $this->lyrics->for($track);

        return (float) collect($sheet?->lines ?? [])
            ->pluck('start')
            ->filter(fn($start) => is_numeric($start))
            ->first();
    }

    /**
     * A temporary copy of the still to animate: the given path or URL, else
     * the primary artist's picture, else the track's artwork.
     */
    protected function still(Track $track, ?string $source): string
    {
        $source ??= $track
            ->artist()
            ->first()
            ?->files['image'] ?? $track->artworkUrl();

        if (! $source) {
            throw new RuntimeException('Give an image: the track has no artist picture or artwork.');
        }

        $path = tempnam(sys_get_temp_dir(), 'still-');

        if (is_file($source)) {
            copy($source, $path);

            return $path;
        }

        $body = Http::timeout(60)
            ->get($source)
            ->throw()
            ->body();

        file_put_contents($path, $body);

        return $path;
    }

    /**
     * The window's vocal, split out with the same model the studio uses.
     */
    protected function vocals(string $song): string
    {
        $parts = $this->splitter->split($song);
        $vocals = $parts['vocals'] ?? throw new ReplicateException('Stem separation found no vocals in that window.');

        foreach ($parts as $name => $file) {
            if ($name !== 'vocals') {
                @unlink($file);
            }
        }

        return $vocals;
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.default'));
    }
}
