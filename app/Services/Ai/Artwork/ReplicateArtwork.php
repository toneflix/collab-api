<?php

namespace App\Services\Ai\Artwork;

use App\Enums\AiEngine;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;

/**
 * Draws artwork with FLUX.1 [schnell] on Replicate, all images in one run.
 */
class ReplicateArtwork implements ArtworkModel
{
    /**
     * Aspect ratios the model takes, by width over height.
     */
    protected const RATIOS = [
        '1:1' => 1.0,
        '4:3' => 4 / 3,
        '3:2' => 1.5,
        '16:9' => 16 / 9,
        '21:9' => 21 / 9,
    ];

    public function engine(): AiEngine
    {
        return AiEngine::REPLICATE;
    }

    public function model(): string
    {
        return (string) config('ai.providers.replicate.models.artwork.default', 'black-forest-labs/flux-schnell');
    }

    public function generate(string $prompt, array $ratio, int $count): array
    {
        $client = ReplicateClient::make();

        $prediction = $client->run($this->model(), [
            'prompt' => $prompt,
            'aspect_ratio' => $this->aspect($ratio[0] / $ratio[1]),
            'num_outputs' => $count,
            'output_format' => 'png',
        ], 300);

        $urls = array_values(array_filter((array) $prediction['output'], fn($url) => ReplicateClient::isFile($url)));

        if (! $urls) {
            throw new ReplicateException('The model returned no images.');
        }

        return array_map(fn(string $url) => $client->download($url), $urls);
    }

    /**
     * The supported ratio closest to the one asked for, at least as wide so
     * cropping only takes height.
     */
    protected function aspect(float $wanted): string
    {
        return collect(self::RATIOS)
            ->filter(fn(float $ratio) => $ratio >= $wanted - 0.001)
            ->sort()
            ->keys()
            ->first() ?? '21:9';
    }
}
