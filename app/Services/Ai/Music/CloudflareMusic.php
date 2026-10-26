<?php

namespace App\Services\Ai\Music;

use App\Enums\AiEngine;
use App\Services\Ai\Cloudflare\CloudflareClient;
use App\Services\Ai\Cloudflare\CloudflareException;

/**
 * A partner music model on Cloudflare Workers AI. It answers with a link to
 * one song, which is downloaded.
 */
abstract class CloudflareMusic extends MusicModel
{
    public const ENGINE = AiEngine::CLOUDFLARE;

    public function generate(array $input, ?string $reference = null): array
    {
        $client = CloudflareClient::make();

        $result = $client->run(static::ID, [
            'prompt' => (string) $input['prompt'],
            ...$this->input($input),
        ]);

        $url = $result['audio'] ?? null;

        if (! is_string($url) || ! preg_match('#^https?://#', $url)) {
            throw new CloudflareException(static::NAME . ' returned no audio.');
        }

        return [new Song($client->download($url), $this->extension($url), meta: ['model' => static::ID])];
    }

    protected function extension(string $url): string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($ext) {
            'wav' => 'wav',
            'ogg', 'opus' => 'ogg',
            default => 'mp3',
        };
    }
}
