<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Composer\Ai\JsonAnswer;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Composer\Ai\LyricWriter;
use RuntimeException;
use Throwable;

/**
 * Asks the Composer language model for the agent's writing, a plan or a
 * song's lyrics, as JSON. Each ask costs the owner a Composer verse's
 * credits, refunded when the answer can't be used.
 */
class AgentWriter
{
    public function __construct(protected LyricModel $model) {}

    public function available(): bool
    {
        return $this->model->available();
    }

    /**
     * Credits one ask costs.
     */
    public static function credits(): int
    {
        return AiSettings::composerCredits(LyricWriter::VERSE);
    }

    /**
     * @return array<string, mixed>
     */
    public function json(AgentRun $run, string $what, string $system, string $prompt, int $maxTokens = 1200): array
    {
        if (! $this->available()) {
            throw new RuntimeException('AI writing isn\'t set up yet.');
        }

        $credits = Credits::for($run->owner);
        $cost = self::credits();
        $spend = $credits->spend($cost, "AI Studio: {$what}", $run);

        try {
            $answer = $this->model->ask($system, $prompt, $maxTokens);
            $data = JsonAnswer::decode($answer);
        } catch (Throwable $e) {
            $credits->refund($spend, "Refund: AI Studio {$what}");

            throw $e;
        }

        $run->increment('credits', $cost);

        return $data;
    }
}
