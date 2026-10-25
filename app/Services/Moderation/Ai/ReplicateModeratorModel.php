<?php

namespace App\Services\Moderation\Ai;

use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateClient;

/**
 * Runs the moderator on a Claude model hosted by Replicate, with the same
 * token as the other AI tools.
 */
class ReplicateModeratorModel implements ModeratorModel
{
    public function ask(string $system, string $prompt, ?string $image = null): string
    {
        $prediction = ReplicateClient::make()
            ->run($this->name(), array_filter([
                'system_prompt' => $system,
                'prompt' => $prompt,
                'max_tokens' => 1024,
                'image' => $image,
            ]), 180);

        return collect((array) $prediction['output'])
            ->flatten()
            ->join('');
    }

    public function name(): string
    {
        return (string) config('ai.providers.replicate.models.moderator.default');
    }

    public function available(): bool
    {
        return AiSettings::configured('replicate') && $this->name() !== '';
    }
}
