<?php

namespace App\Services\Composer\Words;

/**
 * An ARPAbet pronunciation from the CMU dictionary, such as "T AH1 CH", and
 * the keys that group words that rhyme with it.
 */
class Pronunciation
{
    /**
     * @param  list<string>  $phones
     */
    public function __construct(public readonly array $phones) {}

    public static function parse(string $phones): self
    {
        return new self(preg_split('/\s+/', trim(strtoupper($phones)), -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * Joins the pronunciations of the words of a phrase.
     *
     * @param  list<self>  $parts
     */
    public static function join(array $parts): self
    {
        return new self(array_merge(...array_map(fn(self $part) => $part->phones, $parts)));
    }

    public function syllables(): int
    {
        return count($this->vowelPositions());
    }

    /**
     * Stress of each syllable: 1 primary, 2 secondary, 0 none.
     */
    public function stress(): string
    {
        return implode('', array_map(fn(int $i) => substr($this->phones[$i], -1), $this->vowelPositions()));
    }

    /**
     * The sounds from the last stressed vowel to the end ("AH CH" for touch).
     * Words with the same key are perfect rhymes.
     */
    public function rhymeKey(): ?string
    {
        $start = $this->lastStressed();

        return $start === null ? null : implode(' ', array_map($this->bare(...), array_slice($this->phones, $start)));
    }

    /**
     * Only the vowels from the last stressed vowel on ("AA" for hot, got, rock).
     * Words with the same key are near rhymes.
     */
    public function vowelKey(): ?string
    {
        $start = $this->lastStressed();

        return $start === null ? null : $this->vowelsFrom($start);
    }

    /**
     * The last $count vowels ("AH IY" for "touch me"), used for multi-syllable rhymes.
     */
    public function tail(int $count): ?string
    {
        $positions = $this->vowelPositions();

        if (count($positions) < $count) {
            return null;
        }

        return $this->vowelsFrom($positions[count($positions) - $count]);
    }

    /**
     * @return list<int>
     */
    protected function vowelPositions(): array
    {
        return array_keys(array_filter($this->phones, fn(string $phone) => preg_match('/[012]$/', $phone) === 1));
    }

    protected function lastStressed(): ?int
    {
        $positions = $this->vowelPositions();

        foreach (['1', '2'] as $level) {
            foreach (array_reverse($positions) as $i) {
                if (str_ends_with($this->phones[$i], $level)) {
                    return $i;
                }
            }
        }

        return $positions === [] ? null : end($positions);
    }

    protected function vowelsFrom(int $start): string
    {
        $vowels = array_filter(
            array_slice($this->phones, $start),
            fn(string $phone) => preg_match('/[012]$/', $phone) === 1,
        );

        return implode(' ', array_map($this->bare(...), $vowels));
    }

    protected function bare(string $phone): string
    {
        return rtrim($phone, '012');
    }
}
