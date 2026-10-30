<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Replicate\ReplicateBatch;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Draws the still each shot of a music video starts from. Singing shots
 * draw the singer from a reference: the video's portrait, else the first
 * singing still, so the same face carries through the video.
 */
class KeyframeDrawer
{
    public const MODEL = 'bytedance/seedream-4';

    /** Stills drawn at once */
    protected const PARALLEL = 4;

    /** Seconds one still may take */
    protected const TIMEOUT = 300;

    public function __construct(
        protected VideoStorage $storage,
        protected ArtworkImages $images,
    ) {}

    /**
     * Draw and store a still for every shot. A portrait URL makes the singer
     * look like the person in it.
     *
     * @param  iterable<MusicVideoShot>  $shots
     */
    public function draw(MusicVideo $video, iterable $shots, ?string $portrait): void
    {
        $shots = collect($shots)->keyBy('id');
        $reference = $portrait;

        if (! $reference) {
            $first = $shots->firstWhere('kind', MusicVideoShot::PERFORMANCE);

            if ($first) {
                $this->drawAll($video, collect([$first->id => $first]), null);
                $shots->forget($first->id);

                $reference = $this->upload($first->refresh()->keyframe);
            }
        }

        $this->drawAll($video, $shots, $reference);
    }

    /**
     * @param  Collection<int, MusicVideoShot>  $shots
     */
    protected function drawAll(MusicVideo $video, $shots, ?string $reference): void
    {
        $runs = $shots
            ->map(fn(MusicVideoShot $shot) => [self::MODEL, $this->input($video, $shot, $reference)])
            ->all();

        $results = ReplicateBatch::make()
            ->run($runs, self::PARALLEL, self::TIMEOUT);

        foreach ($results as $id => $result) {
            if ($result instanceof Throwable) {
                throw $result;
            }

            $this->keep($video, $shots[$id], $result['output']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function input(MusicVideo $video, MusicVideoShot $shot, ?string $reference): array
    {
        $style = collect($video->style ?? [])
            ->only(['look', 'setting', 'palette', 'wardrobe'])
            ->filter()
            ->implode('. ');
        $singer = $shot->kind === MusicVideoShot::PERFORMANCE
            ? ($reference ? ' The singer is the person in the reference image.' : ' The singer: ' . ($video->style['character'] ?? 'the artist') . '.')
            : '';

        return array_filter([
            'prompt' => "Music video still. {$shot->prompt}{$singer} {$style}. Cinematic, photographic, no text.",
            'image_input' => $shot->kind === MusicVideoShot::PERFORMANCE && $reference ? [$reference] : null,
            'aspect_ratio' => $video->ratio,
            'size' => '2K',
        ]);
    }

    protected function keep(MusicVideo $video, MusicVideoShot $shot, mixed $output): void
    {
        $url = is_array($output) ? reset($output) : $output;

        if (! ReplicateClient::isFile($url)) {
            throw new ReplicateException('The image model returned no still.');
        }

        $local = ReplicateClient::make()->download($url);

        try {
            $jpeg = $this->images
                ->cropped($local, $video->ratioParts())
                ->getRealPath();
            $old = $shot->keyframe;
            $file = $this->storage->put($video, $jpeg, "keyframe-{$shot->position}", 'jpg');

            @unlink($jpeg);

            $shot
                ->forceFill(['keyframe' => $file, 'clip' => null])
                ->save();

            $this->storage->delete($old);
        } finally {
            @unlink($local);
        }
    }

    /**
     * Put a stored still where the models can read it.
     */
    public function upload(?string $file): ?string
    {
        $local = $this->storage->local($file);

        if (! $local) {
            return null;
        }

        try {
            return ReplicateClient::make()->upload($local, 'image/jpeg');
        } finally {
            @unlink($local);
        }
    }
}
