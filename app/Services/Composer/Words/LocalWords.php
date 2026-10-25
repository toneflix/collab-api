<?php

namespace App\Services\Composer\Words;

use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lookups answered from the CMU dictionary in the `words` table. It has no
 * meanings, so related words and definitions come back empty.
 */
class LocalWords implements WordSource
{
    public function name(): string
    {
        return 'local';
    }

    public function complete(string $prefix, int $max): array
    {
        $words = Word::query()
            ->where('word', 'like', addcslashes($prefix, '%_\\') . '%')
            ->orderBy('rank')
            ->orderByRaw('length(word)')
            ->orderBy('word')
            ->limit($max)
            ->get(['word', 'syllables']);

        return $this->rows($words);
    }

    public function rhymes(string $word, string $type, int $max): array
    {
        $pronunciation = $this->pronounce($word);

        if (! $pronunciation) {
            return [];
        }

        $rhymeKey = $pronunciation->rhymeKey();
        $vowelKey = $pronunciation->vowelKey();

        $words = Word::query()
            ->where('word', '!=', mb_strtolower($word))
            ->when(
                $type === 'perfect',
                fn(Builder $q) => $q->where('rhyme_key', $rhymeKey),
                fn(Builder $q) => $q
                    ->where('vowel_key', $vowelKey)
                    ->where('rhyme_key', '!=', $rhymeKey),
            )
            ->orderBy('rank')
            ->orderBy('syllables')
            ->orderBy('word')
            ->limit($max)
            ->get(['word', 'syllables']);

        return $this->rows($words);
    }

    /**
     * Words whose last vowels match the end of a phrase, such as "lovely"
     * for "touch me". Matches on three vowels when the phrase has them, then
     * fills up with two-vowel matches.
     *
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function multi(string $phrase, int $max): array
    {
        $parts = collect(preg_split('/\s+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY))
            ->take(-3)
            ->map(fn(string $part) => $this->pronounce($part));

        if ($parts->isEmpty() || $parts->contains(null)) {
            return [];
        }

        $pronunciation = Pronunciation::join($parts->all());
        $exclude = [mb_strtolower(trim($phrase))];
        $rows = [];

        foreach ([3, 2] as $size) {
            $tail = $pronunciation->tail($size);

            if (! $tail || count($rows) >= $max) {
                continue;
            }

            $found = $this->rows($this->tailMatches($size, $tail, $exclude, $max - count($rows)));
            $rows = array_merge($rows, $found);
            $exclude = array_merge($exclude, array_column($found, 'word'));
        }

        return $rows;
    }

    public function related(string $word, string $type, int $max): array
    {
        return [];
    }

    public function definitions(string $word): array
    {
        return [];
    }

    public function pronounce(string $word): ?Pronunciation
    {
        $phones = Word::query()
            ->where('word', mb_strtolower(trim($word)))
            ->value('phones');

        return $phones ? Pronunciation::parse($phones) : null;
    }

    /**
     * Words ending in the given vowels. Words stressed where the rhyme starts
     * come first, so "lovely" beats "abee" for "touch me".
     *
     * @param  list<string>  $exclude
     * @return Collection<int, Word>
     */
    protected function tailMatches(int $size, string $tail, array $exclude, int $max): Collection
    {
        return Word::query()
            ->where("tail{$size}", $tail)
            ->whereNotIn('word', $exclude)
            ->orderByRaw("case when substr(stress, length(stress) - {$size} + 1, 1) = '0' then 1 else 0 end")
            ->orderBy('rank')
            ->orderBy('syllables')
            ->orderBy('word')
            ->limit($max)
            ->get(['word', 'syllables']);
    }

    /**
     * @param  iterable<Word>  $words
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    protected function rows(iterable $words): array
    {
        $rows = [];

        foreach ($words as $word) {
            $rows[] = ['word' => $word->word, 'syllables' => $word->syllables, 'score' => null];
        }

        return $rows;
    }
}
