<?php

namespace App\Services\Moderation\Ai;

use App\Models\AiReview;

/**
 * The moderator's answer, read from the model's JSON.
 */
final class Verdict
{
    public function __construct(
        public readonly string $decision,
        public readonly int $confidence,
        public readonly string $summary,
        public readonly ?string $note,
    ) {}

    /**
     * Read the model's answer. When a decision is required, an "unsure"
     * answer counts as a rejection, which grants nothing.
     */
    public static function parse(string $text, bool $mustDecide): self
    {
        $json = str($text)->after('```json')->before('```')->trim()->toString();
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($json, $start, $end - $start + 1), true) : null;

        $decision = strtolower((string) ($data['decision'] ?? ''));

        if (! is_array($data) || ! in_array($decision, [AiReview::APPROVE, AiReview::REJECT, AiReview::UNSURE], true)) {
            throw new InvalidVerdict('The AI moderator gave an answer that could not be read.');
        }

        if ($mustDecide && $decision === AiReview::UNSURE) {
            $decision = AiReview::REJECT;
        }

        $note = trim((string) ($data['note'] ?? '')) ?: null;

        return new self(
            $decision,
            max(0, min(100, (int) ($data['confidence'] ?? 0))),
            str((string) ($data['summary'] ?? ''))->limit(2000)->toString(),
            $note ? str($note)->limit(2000)->toString() : null,
        );
    }

    /**
     * Whether the verdict is firm enough to act on without a person.
     */
    public function confident(int $threshold): bool
    {
        return $this->decision !== AiReview::UNSURE && $this->confidence >= $threshold;
    }
}
