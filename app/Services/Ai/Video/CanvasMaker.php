<?php

namespace App\Services\Ai\Video;

use App\Models\TrackCanvas;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Video\VideoFiles;
use Illuminate\Support\Facades\Http;

/**
 * Makes a track's canvas with AI: redraws the artwork as a tall still, animates
 * it with the music video b-roll model and dissolves the clip's end into its
 * start so it loops.
 */
class CanvasMaker
{
    /** Seconds the model animates; the dissolve takes one of them */
    public const SECONDS = 7;

    protected const FADE = 1.0;

    protected const TIMEOUT = 600;

    public function __construct(
        protected Canvases $canvases,
        protected ArtworkImages $images,
        protected VideoFiles $files,
    ) {}

    public function make(TrackCanvas $canvas): TrackCanvas
    {
        $client = ReplicateClient::make();
        $temp = [];

        try {
            $artwork = $temp[] = $this->artwork($canvas);
            $cover = $client->upload($artwork, 'image/jpeg');
            $drawn = $client->run(KeyframeDrawer::MODEL, $this->stillInput($canvas, $cover), self::TIMEOUT);
            $still = $temp[] = $this->download($client, $drawn['output'] ?? null, 'The image model returned no image.');
            $tall = $temp[] = $this->images
                ->cropped($still, [9, 16])
                ->getRealPath();
            $image = $client->upload($tall, 'image/jpeg');
            $model = AiSettings::videoBrollModel();
            $animated = $client->run($model->model(), $model->input($image, null, $this->motionPrompt($canvas), self::SECONDS), self::TIMEOUT);
            $clip = $temp[] = $this->download($client, $animated['output'] ?? null, 'The video model returned no clip.');
            $loop = $temp[] = $this->files->loop($clip, TrackCanvas::SIZE, TrackCanvas::MAX_SECONDS, self::FADE);

            return $this->canvases->finish($canvas, $loop);
        } finally {
            array_map(fn(string $file) => @unlink($file), $temp);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function stillInput(TrackCanvas $canvas, string $cover): array
    {
        $brief = trim((string) $canvas->brief);
        $genre = $canvas->track?->genre?->name;
        $mood = $genre ? " The mood fits {$genre} music." : '';

        return [
            'prompt' => 'A tall 9:16 version of the reference cover art for a looping music visual: the same subject, colours and style, '
                . "extended to fill the whole tall frame.{$mood} {$brief} No text, no letters, no logos, no borders.",
            'image_input' => [$cover],
            'aspect_ratio' => '9:16',
            'size' => '2K',
        ];
    }

    protected function motionPrompt(TrackCanvas $canvas): string
    {
        $brief = trim((string) $canvas->brief);

        return 'Slow, subtle, hypnotic motion that can loop: drifting light, haze, particles, hair or fabric moving gently. '
            . "The camera stays still and nothing new enters the frame. {$brief}";
    }

    /**
     * A local copy of the track's artwork.
     */
    protected function artwork(TrackCanvas $canvas): string
    {
        $url = $canvas->track?->artworkUrl() ?? throw new ReplicateException('The track has no artwork.');
        $path = tempnam(sys_get_temp_dir(), 'canvas-');
        $body = Http::timeout(60)
            ->get($url)
            ->throw()
            ->body();

        file_put_contents($path, $body);

        return $path;
    }

    protected function download(ReplicateClient $client, mixed $output, string $missing): string
    {
        $url = is_array($output) ? reset($output) : $output;

        if (! ReplicateClient::isFile($url)) {
            throw new ReplicateException($missing);
        }

        return $client->download($url);
    }
}
