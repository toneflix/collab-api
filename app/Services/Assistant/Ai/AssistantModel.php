<?php

namespace App\Services\Assistant\Ai;

/**
 * The language model behind the admin assistant.
 */
interface AssistantModel
{
    /**
     * Ask the model and return its raw text answer.
     */
    public function ask(string $system, string $prompt): string;

    public function name(): string;

    public function available(): bool;
}
