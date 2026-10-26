<?php

namespace App\Services\Moderation\Ai;

use App\Enums\AiEngine;
use App\Services\Ai\Cloudflare\CloudflareClient;

/**
 * Runs the moderator on a language model on Cloudflare Workers AI, Claude
 * Haiku by default.
 */
class CloudflareModeratorModel implements ModeratorModel
{
    public function ask(string $system, string $prompt, ?string $image = null): string
    {
        return CloudflareClient::make()
            ->chat($this->name(), $system, $prompt, 1024, $image, 180);
    }

    public function name(): string
    {
        return (string) config('ai.providers.cloudflare.models.moderator.default');
    }

    public function available(): bool
    {
        return AiEngine::CLOUDFLARE->available() && $this->name() !== '';
    }
}
