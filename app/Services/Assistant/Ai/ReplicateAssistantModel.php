<?php

namespace App\Services\Assistant\Ai;

use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateClient;

/**
 * Runs the assistant on a language model hosted by Replicate.
 */
class ReplicateAssistantModel implements AssistantModel
{
    public function ask(string $system, string $prompt): string
    {
        $prediction = ReplicateClient::make()
            ->run($this->name(), [
                'system_prompt' => $system,
                'prompt' => $prompt,
                'max_tokens' => 2048,
            ], 180);

        return collect((array) $prediction['output'])
            ->flatten()
            ->join('');
    }

    public function name(): string
    {
        return (string) config('ai.providers.replicate.models.assistant.default');
    }

    public function available(): bool
    {
        return AiSettings::configured('replicate') && $this->name() !== '';
    }
}
