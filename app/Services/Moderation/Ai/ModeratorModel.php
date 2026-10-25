<?php

namespace App\Services\Moderation\Ai;

/**
 * The language model behind the AI moderator.
 */
interface ModeratorModel
{
    /**
     * Ask the model and return its raw text answer.
     */
    public function ask(string $system, string $prompt, ?string $image = null): string;

    /**
     * The model's name, kept on each review.
     */
    public function name(): string;

    public function available(): bool;
}
