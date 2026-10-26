<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Enums\ArtworkKind;
use App\Models\AiJob;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Ai\Artwork\ArtworkModel;
use App\Services\Ai\Artwork\CloudflareArtwork;
use App\Services\Ai\Artwork\ReplicateArtwork;

/**
 * Draws a few images for a piece of artwork, like a release cover or an
 * artist photo, for the person to pick from.
 */
class GenerateArtwork implements AiTask
{
    /**
     * The model for each engine that draws artwork.
     *
     * @var array<string, class-string<ArtworkModel>>
     */
    public const MODELS = [
        'replicate' => ReplicateArtwork::class,
        'cloudflare' => CloudflareArtwork::class,
    ];

    public function __construct(protected ArtworkImages $images) {}

    /**
     * The model a job runs on: the one it started with, or the admin's pick.
     *
     * @param  array<string, mixed>  $input
     */
    public static function model(array $input = []): ArtworkModel
    {
        $engine = AiEngine::tryFrom((string) ($input['artwork_engine'] ?? '')) ?? AiSettings::artworkEngine();

        return app(self::MODELS[$engine->value] ?? ReplicateArtwork::class);
    }

    public function credits(array $input): int
    {
        return AiSettings::artworkCredits();
    }

    public function describe(array $input): string
    {
        return 'Artwork: ' . str((string) ($input['prompt'] ?? ''))->limit(60);
    }

    public function engine(array $input): AiEngine
    {
        return self::model($input)->engine();
    }

    public function handle(AiJob $job): array
    {
        $input = $job->input;
        $kind = ArtworkKind::from($input['artwork_kind']);
        $prompt = $kind->prompt((string) $input['prompt'], $input);
        $model = self::model($input);
        $paths = $model->generate($prompt, $kind->ratio(), (int) ($input['count'] ?? 1));

        try {
            $images = $this->images->keep($job, $paths, $kind->ratio());
        } finally {
            array_map(fn(string $path) => @unlink($path), $paths);
        }

        return ['images' => $images];
    }
}
