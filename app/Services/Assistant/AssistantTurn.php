<?php

namespace App\Services\Assistant;

/**
 * One answer from the assistant's model: what to tell the admin, and the
 * tools it wants to call before answering again.
 */
class AssistantTurn
{
    /**
     * @param  list<array{tool: string, input: array<string, mixed>}>  $calls
     */
    public function __construct(
        public readonly string $reply,
        public readonly array $calls = [],
    ) {}

    /**
     * Read the model's JSON answer. An answer that isn't JSON is taken as a
     * plain reply.
     */
    public static function parse(string $raw): self
    {
        $text = trim($raw);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        $json = $start !== false && $end !== false
            ? json_decode(substr($text, $start, $end - $start + 1), true)
            : null;

        if (! is_array($json) || (! isset($json['reply']) && ! isset($json['calls']))) {
            return new self($text);
        }

        $calls = collect(is_array($json['calls'] ?? null) ? $json['calls'] : [])
            ->filter(fn($call) => is_array($call) && is_string($call['tool'] ?? null))
            ->map(fn(array $call) => [
                'tool' => $call['tool'],
                'input' => is_array($call['input'] ?? null) ? $call['input'] : [],
            ])
            ->values()
            ->all();

        return new self(trim((string) ($json['reply'] ?? '')), $calls);
    }
}
