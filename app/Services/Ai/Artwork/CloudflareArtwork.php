<?php

namespace App\Services\Ai\Artwork;

use App\Enums\AiEngine;
use App\Services\Ai\Cloudflare\CloudflareClient;
use App\Services\Ai\Cloudflare\CloudflareException;
use Throwable;

/**
 * Draws artwork with FLUX.1 [schnell] on Workers AI, one square image per
 * run.
 */
class CloudflareArtwork implements ArtworkModel
{
    public function engine(): AiEngine
    {
        return AiEngine::CLOUDFLARE;
    }

    public function model(): string
    {
        return (string) config('ai.providers.cloudflare.models.artwork.default', '@cf/black-forest-labs/flux-1-schnell');
    }

    public function generate(string $prompt, array $ratio, int $count): array
    {
        $client = CloudflareClient::make();
        $paths = [];

        try {
            for ($i = 0; $i < $count; $i++) {
                $result = $client->run($this->model(), [
                    'prompt' => $prompt,
                    'steps' => 8,
                    'seed' => random_int(1, 2_147_483_647),
                ], 120);

                $image = base64_decode((string) ($result['image'] ?? ''), true);

                if (! $image) {
                    throw new CloudflareException('The model returned no image.');
                }

                $path = tempnam(sys_get_temp_dir(), 'cf-art-');
                file_put_contents($path, $image);
                $paths[] = $path;
            }
        } catch (Throwable $e) {
            array_map(fn(string $path) => @unlink($path), $paths);

            throw $e;
        }

        return $paths;
    }
}
