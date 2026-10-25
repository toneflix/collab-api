<?php

namespace App\Services\Composer\Ai;

/**
 * The language model that suggests lyrics in Composer.
 */
interface LyricModel
{
    /**
     * Ask the model and return its raw text answer.
     */
    public function ask(string $system, string $prompt): string;

    public function name(): string;

    public function available(): bool;
}
