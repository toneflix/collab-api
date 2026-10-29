<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
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
        $spend = $credits->spend($cost, "Content agent: {$what}", $run);

        try {
            $data = $this->decode($this->model->ask($system, $prompt, $maxTokens));
        } catch (Throwable $e) {
            $credits->refund($spend, "Refund: content agent {$what}");

            throw $e;
        }

        $run->increment('credits', $cost);

        return $data;
    }

    /**
     * The first JSON object in the model's answer.
     *
     * @return array<string, mixed>
     */
    protected function decode(string $answer): array
    {
        $json = str($answer)
            ->after('```json')
            ->before('```')
            ->trim()
            ->toString();
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($json, $start, $end - $start + 1), true) : null;

        if (! is_array($data) && $start !== false && substr_count($json, '{') > substr_count($json, '}')) {
            throw new RuntimeException('The AI answer was cut off before it finished. Try again, or ask for less in one run.');
        }

        if (! is_array($data)) {
            throw new RuntimeException('The AI answer wasn\'t valid JSON.');
        }

        return $data;
    }
}
