<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Replicate\ReplicateBatch;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Draws a music video's character sheets, then the still each shot starts
 * from. Every still of a character is drawn from that character's sheet, so
 * people look the same from shot to shot. The singer's sheet follows the
 * video's portrait when it has one.
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
        protected ShotTakes $takes,
    ) {}

    /**
     * Draw what's missing: character sheets first, then a still for every
     * shot that has none. Drawings that come back are kept even when others
     * fail, so a retry only draws the rest.
     *
     * @param  iterable<MusicVideoCharacter>  $characters
     * @param  iterable<MusicVideoShot>  $shots
     */
    public function draw(MusicVideo $video, iterable $characters, iterable $shots, ?string $portrait): void
    {
        $characters = collect($characters)->keyBy('key');

        $this->drawSheets($video, $characters, $portrait);

        $sheets = $this->sheets($characters);
        $missing = collect($shots)
            ->keyBy('id')
            ->filter(fn(MusicVideoShot $shot) => blank($shot->keyframe));

        $runs = $missing
            ->map(fn(MusicVideoShot $shot) => [self::MODEL, $this->input($video, $shot, $characters, $sheets)])
            ->all();

        $this->runAll($runs, fn(int $id, mixed $output) => $this->keep($video, $missing[$id], $output));
    }

    /**
     * Draw a new still for one shot, in place of the one it has.
     */
    public function redraw(MusicVideo $video, MusicVideoShot $shot, ?string $portrait): void
    {
        $characters = $video
            ->characters()
            ->get()
            ->keyBy('key');

        $this->drawSheets($video, $characters, $portrait);

        $sheets = $this->sheets($characters);
        $runs = [$shot->id => [self::MODEL, $this->input($video, $shot, $characters, $sheets)]];

        $this->runAll($runs, fn(int $id, mixed $output) => $this->keep($video, $shot, $output));
    }

    /**
     * Sheet URLs the models can read, by character key.
     *
     * @param  Collection<string, MusicVideoCharacter>  $characters
     * @return Collection<string, string>
     */
    protected function sheets(Collection $characters): Collection
    {
        return $characters
            ->filter(fn(MusicVideoCharacter $character) => filled($character->sheet))
            ->map(fn(MusicVideoCharacter $character) => $this->upload($character->sheet))
            ->filter();
    }

    /**
     * @param  Collection<string, MusicVideoCharacter>  $characters
     */
    protected function drawSheets(MusicVideo $video, Collection $characters, ?string $portrait): void
    {
        $missing = $characters
            ->filter(fn(MusicVideoCharacter $character) => blank($character->sheet))
            ->keyBy('id');

        $runs = $missing
            ->map(fn(MusicVideoCharacter $character) => [self::MODEL, $this->sheetInput($video, $character, $portrait)])
            ->all();

        $this->runAll($runs, fn(int $id, mixed $output) => $this->keepSheet($video, $missing[$id], $output));
    }

    /**
     * Run the predictions and hand each output to `$keep`. Every success is
     * kept before the first failure is thrown.
     *
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $runs
     * @param  callable(int, mixed): void  $keep
     */
    protected function runAll(array $runs, callable $keep): void
    {
        if (! $runs) {
            return;
        }

        $results = ReplicateBatch::make()
            ->run($runs, self::PARALLEL, self::TIMEOUT);
        $error = null;

        foreach ($results as $id => $result) {
            try {
                if ($result instanceof Throwable) {
                    throw $result;
                }

                $keep($id, $result['output']);
            } catch (Throwable $e) {
                $error ??= $e;
            }
        }

        if ($error) {
            throw $error;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function sheetInput(MusicVideo $video, MusicVideoCharacter $character, ?string $portrait): array
    {
        $style = $this->style($video, ['look', 'palette']);
        $likeness = $character->singer && $portrait ? ' Their face and hair match the person in the reference photo.' : '';

        return array_filter([
            'prompt' => "Character reference sheet for a music video. {$character->name}: {$character->description}.{$likeness} "
                . 'Full body front view, side view and back view, plus a close-up of the face, all the same person in the same outfit, '
                . "standing on a plain light grey background, even studio light. {$style}. Photographic, no text, no labels.",
            'image_input' => $character->singer && $portrait ? [$portrait] : null,
            'aspect_ratio' => '16:9',
            'size' => '2K',
        ]);
    }

    /**
     * @param  Collection<string, MusicVideoCharacter>  $characters
     * @param  Collection<string, string>  $sheets  Sheet URLs by character key
     * @return array<string, mixed>
     */
    protected function input(MusicVideo $video, MusicVideoShot $shot, Collection $characters, Collection $sheets): array
    {
        $style = $this->style($video, ['look', 'setting', 'palette']);
        $cast = collect($shot->characters ?? [])
            ->filter(fn(string $key) => $characters->has($key))
            ->values();
        $references = $cast
            ->filter(fn(string $key) => $sheets->has($key))
            ->values();
        $people = $cast
            ->map(function (string $key) use ($characters, $references) {
                $character = $characters[$key];
                $sheet = $references->search($key);

                return $sheet === false
                    ? "{$character->name} is {$character->description}."
                    : "{$character->name} is the person on reference sheet " . ($sheet + 1) . ', same face, hair and outfit.';
            })
            ->implode(' ');

        return array_filter([
            'prompt' => trim("Music video still. {$shot->prompt} {$people} {$style}. Cinematic, photographic, one moment of the scene, not a sheet, no text."),
            'image_input' => $references
                ->map(fn(string $key) => $sheets[$key])
                ->all() ?: null,
            'aspect_ratio' => $video->ratio,
            'size' => '2K',
        ]);
    }

    /**
     * @param  list<string>  $parts
     */
    protected function style(MusicVideo $video, array $parts): string
    {
        return collect($video->style ?? [])
            ->only($parts)
            ->filter()
            ->implode('. ');
    }

    protected function keepSheet(MusicVideo $video, MusicVideoCharacter $character, mixed $output): void
    {
        $local = $this->download($output);

        try {
            $file = $this->storage->put($video, $local, "character-{$character->key}", 'jpg');

            $character
                ->forceFill(['sheet' => $file])
                ->save();
        } finally {
            @unlink($local);
        }
    }

    protected function keep(MusicVideo $video, MusicVideoShot $shot, mixed $output): void
    {
        $local = $this->download($output);

        try {
            $jpeg = $this->images
                ->cropped($local, $video->ratioParts())
                ->getRealPath();
            $old = $shot->keyframe;
            $file = $this->storage->put($video, $jpeg, "keyframe-{$shot->position}", 'jpg');

            @unlink($jpeg);

            $shot
                ->forceFill(['keyframe' => $file, 'take_id' => null, 'motion' => null, 'drawing' => false])
                ->save();

            $this->takes->dropKeyframes($video, [$old]);
        } finally {
            @unlink($local);
        }
    }

    /**
     * Fetch the image a prediction made to a local file.
     */
    protected function download(mixed $output): string
    {
        $url = is_array($output) ? reset($output) : $output;

        if (! ReplicateClient::isFile($url)) {
            throw new ReplicateException('The image model returned no image.');
        }

        return ReplicateClient::make()->download($url);
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
