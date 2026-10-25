<?php

namespace App\Services\Composer\Words;

/**
 * Where Composer looks words up. Each method returns rows of
 * ['word' => string, 'syllables' => ?int, 'score' => ?int], best first.
 */
interface WordSource
{
    public function name(): string;

    /**
     * Words that start with what the writer is typing.
     *
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function complete(string $prefix, int $max): array;

    /**
     * Rhymes of one type: perfect, near or sounds (sounds like).
     *
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function rhymes(string $word, string $type, int $max): array;

    /**
     * Related words of one type: means (similar meaning), follows (often
     * comes after it) or triggers (often said with it).
     *
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function related(string $word, string $type, int $max): array;

    /**
     * @return list<array{partOfSpeech: ?string, text: string}>
     */
    public function definitions(string $word): array;
}
