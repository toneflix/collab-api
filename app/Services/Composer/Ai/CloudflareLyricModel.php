<?php

namespace App\Services\Composer\Ai;

use App\Enums\AiEngine;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Cloudflare\CloudflareClient;

/**
 * Suggests lyrics with a language model on Cloudflare Workers AI, Claude Haiku by default.
 */
class CloudflareLyricModel implements LyricModel
{
    public function ask(string $system, string $prompt, int $maxTokens = 1200): string
    {
        return CloudflareClient::make()
            ->chat($this->name(), $system, $prompt, $maxTokens, timeout: $maxTokens > 1200 ? 180 : 60);
    }

    public function name(): string
    {
        return AiSettings::composerCloudflareModel();
    }

    public function available(): bool
    {
        return AiEngine::CLOUDFLARE->available() && $this->name() !== '';
    }
}
