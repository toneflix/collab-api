<?php

namespace App\Services\Composer\Words;

use App\Models\Word;
use App\Services\Composer\ComposerSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one place Composer looks words up. Answers from the admin's word source
 * are cached for a week; when that source fails, the local dictionary answers.
 */
class Words
{
    public const RHYME_TYPES = ['perfect', 'near', 'sounds', 'multi'];

    public const RELATED_TYPES = ['means', 'follows', 'triggers'];

    public const CACHE_DAYS = 7;

    public function __construct(
        protected LocalWords $local,
        protected DatamuseWords $datamuse,
    ) {}

    public function source(): WordSource
    {
        return ComposerSettings::wordSource() === 'local' ? $this->local : $this->datamuse;
    }

    /**
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function complete(string $prefix, int $max = 10): array
    {
        return $this->lookup(
            'complete',
            [mb_strtolower($prefix), $max],
            fn(WordSource $source) => $source->complete(mb_strtolower($prefix), $max),
        );
    }

    /**
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function rhymes(string $word, string $type = 'perfect', int $max = 60): array
    {
        if ($type === 'multi') {
            return $this->local->multi($word, $max);
        }

        return $this->lookup(
            'rhymes',
            [mb_strtolower($word), $type, $max],
            fn(WordSource $source) => $source->rhymes($word, $type, $max),
        );
    }

    /**
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    public function related(string $word, string $type = 'means', int $max = 60): array
    {
        return $this->lookup(
            'related',
            [mb_strtolower($word), $type, $max],
            fn(WordSource $source) => $source->related($word, $type, $max),
        );
    }

    /**
     * @return list<array{partOfSpeech: ?string, text: string}>
     */
    public function definitions(string $word): array
    {
        return $this->lookup(
            'definitions',
            [mb_strtolower($word)],
            fn(WordSource $source) => $source->definitions($word),
        );
    }

    /**
     * Syllable counts and stress for a batch of words, from the dictionary
     * when it knows the word and from the spelling when it does not.
     *
     * @param  list<string>  $words
     * @return list<array{word: string, syllables: int, stress: ?string, known: bool}>
     */
    public function syllables(array $words): array
    {
        $wanted = collect($words)
            ->map(fn(string $word) => mb_strtolower(trim($word)))
            ->filter()
            ->unique()
            ->values();

        $known = Word::query()
            ->whereIn('word', $wanted->all())
            ->get(['word', 'syllables', 'stress'])
            ->keyBy('word');

        return $wanted
            ->map(fn(string $word) => $known->has($word)
                ? ['word' => $word, 'syllables' => $known[$word]->syllables, 'stress' => $known[$word]->stress, 'known' => true]
                : ['word' => $word, 'syllables' => SyllableGuess::count($word), 'stress' => null, 'known' => false])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $args
     * @param  callable(WordSource): array<int, array<string, mixed>>  $call
     * @return array<int, array<string, mixed>>
     */
    protected function lookup(string $operation, array $args, callable $call): array
    {
        $source = $this->source();

        if ($source === $this->local) {
            return $call($this->local);
        }

        $key = "words:{$source->name()}:{$operation}:" . md5(json_encode($args));

        try {
            return Cache::remember($key, now()->addDays(self::CACHE_DAYS), fn() => $call($source));
        } catch (WordSourceUnavailable $e) {
            Log::warning("Composer word lookup fell back to the local dictionary: {$e->getMessage()}");

            return $call($this->local);
        }
    }
}
