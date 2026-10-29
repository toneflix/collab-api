<?php

namespace App\Services\Composer\Ai;

use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateClient;

/**
 * Suggests lyrics with a language model hosted by Replicate, Claude Haiku by default.
 */
class ReplicateLyricModel implements LyricModel
{
    public function ask(string $system, string $prompt, int $maxTokens = 1200): string
    {
        $prediction = ReplicateClient::make()
            ->run($this->name(), [
                'system_prompt' => $system,
                'prompt' => $prompt,
                'max_tokens' => $maxTokens,
            ], LyricWriter::timeout($maxTokens));

        return collect((array) $prediction['output'])
            ->flatten()
            ->join('');
    }

    public function name(): string
    {
        return AiSettings::composerModel();
    }

    public function available(): bool
    {
        return AiSettings::configured('replicate') && $this->name() !== '';
    }
}
