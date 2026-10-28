<?php

namespace App\Services\Assistant\Ai;

use App\Enums\AiEngine;
use App\Services\Ai\Cloudflare\CloudflareClient;

/**
 * Runs the assistant on a language model on Cloudflare Workers AI.
 */
class CloudflareAssistantModel implements AssistantModel
{
    public function ask(string $system, string $prompt): string
    {
        return CloudflareClient::make()
            ->chat($this->name(), $system, $prompt, 2048, null, 180);
    }

    public function name(): string
    {
        return (string) config('ai.providers.cloudflare.models.assistant.default');
    }

    public function available(): bool
    {
        return AiEngine::CLOUDFLARE->available() && $this->name() !== '';
    }
}
