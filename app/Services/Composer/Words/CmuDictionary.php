<?php

namespace App\Services\Composer\Words;

use App\Models\Word;
use RuntimeException;

/**
 * Loads the CMU Pronouncing Dictionary (BSD licensed, shipped in
 * database/data/cmudict) into the `words` table, a thousand words at a time,
 * ranked by how common SCOWL says each word is. Only the first pronunciation
 * of each word is kept.
 */
class CmuDictionary
{
    public const BATCH = 1000;

    public const UNRANKED = 99;

    /**
     * @var array<string, int>
     */
    protected array $ranks = [];

    public static function defaultPath(): string
    {
        return database_path('data/cmudict/cmudict.dict.gz');
    }

    public static function ranksPath(): string
    {
        return database_path('data/scowl/ranks.txt.gz');
    }

    /**
     * @param  (callable(int): void)|null  $progress  called with the running total after each batch
     */
    public function import(?string $path = null, ?callable $progress = null): int
    {
        $path ??= self::defaultPath();
        $this->ranks = $this->loadRanks(self::ranksPath());
        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("The dictionary file {$path} could not be opened.");
        }

        $batch = [];
        $total = 0;

        try {
            while (($line = gzgets($handle)) !== false) {
                $row = $this->row($line);

                if ($row === null || isset($batch[$row['word']])) {
                    continue;
                }

                $batch[$row['word']] = $row;

                if (count($batch) >= self::BATCH) {
                    $total += $this->save($batch);
                    $batch = [];
                    $progress && $progress($total);
                }
            }

            $total += $this->save($batch);
            $progress && $progress($total);
        } finally {
            gzclose($handle);
        }

        return $total;
    }

    /**
     * One dictionary line, such as "touch T AH1 CH" or "touch(2) ...", as a
     * `words` row. Alternate pronunciations, comments (# or ;;;) and blank lines give null.
     *
     * @return array<string, mixed>|null
     */
    public function row(string $line): ?array
    {
        $line = trim(explode('#', $line, 2)[0]);

        if ($line === '' || str_starts_with($line, ';;;') || ! preg_match('/^(\S+)\s+(.+)$/', $line, $match) || str_contains($match[1], '(')) {
            return null;
        }

        $pronunciation = Pronunciation::parse($match[2]);
        $word = mb_strtolower($match[1]);

        return [
            'word' => $word,
            'phones' => implode(' ', $pronunciation->phones),
            'syllables' => $pronunciation->syllables(),
            'rank' => $this->ranks[$word] ?? self::UNRANKED,
            'stress' => $pronunciation->stress() ?: null,
            'rhyme_key' => $pronunciation->rhymeKey(),
            'vowel_key' => $pronunciation->vowelKey(),
            'tail2' => $pronunciation->tail(2),
            'tail3' => $pronunciation->tail(3),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $batch
     */
    protected function save(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        Word::query()->upsert(
            array_values($batch),
            ['word'],
            ['phones', 'syllables', 'rank', 'stress', 'rhyme_key', 'vowel_key', 'tail2', 'tail3'],
        );

        return count($batch);
    }

    /**
     * Lines of "word<TAB>level" from SCOWL.
     *
     * @return array<string, int>
     */
    protected function loadRanks(string $path): array
    {
        $handle = @gzopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $ranks = [];

        while (($line = gzgets($handle)) !== false) {
            [$word, $level] = array_pad(explode("\t", trim($line), 2), 2, null);

            if ($word !== '' && is_numeric($level)) {
                $ranks[$word] = (int) $level;
            }
        }

        gzclose($handle);

        return $ranks;
    }
}
