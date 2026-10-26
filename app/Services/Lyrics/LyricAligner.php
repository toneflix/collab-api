<?php

namespace App\Services\Lyrics;

/**
 * Puts times on written lyrics: from the words heard in the audio, or from an
 * earlier version of the same sheet.
 */
class LyricAligner
{
    /** Words compared at most, from each side, so long songs stay quick */
    public const MAX_WORDS = 2500;

    /**
     * Time the written lines from transcribed ones, keeping the written words.
     * Words are matched in order; a line with no word heard gets no time.
     *
     * @param  list<array{start: ?float, end: ?float, text: string}>  $written
     * @param  list<array{start: float, end: float, text: string}>  $heard
     * @return list<array{start: ?float, end: ?float, text: string}>
     */
    public function align(array $written, array $heard): array
    {
        $writtenWords = $this->writtenWords($written);
        $heardWords = $this->heardWords($heard);
        $pairs = $this->match(array_column($writtenWords, 'word'), array_column($heardWords, 'word'));

        $times = [];

        foreach ($pairs as [$w, $h]) {
            $line = $writtenWords[$w]['line'];
            $times[$line]['start'] ??= $heardWords[$h]['start'];
            $times[$line]['end'] = $heardWords[$h]['end'];
        }

        $lines = [];

        foreach ($written as $i => $line) {
            $lines[] = [
                'start' => isset($times[$i]) ? round($times[$i]['start'], 2) : null,
                'end' => isset($times[$i]) ? round($times[$i]['end'], 2) : null,
                'text' => $line['text'],
            ];
        }

        return $lines;
    }

    /**
     * New lines of text, keeping the times of the old lines they match in order.
     *
     * @param  list<array{start: ?float, end: ?float, text: string}>  $old
     * @param  list<string>  $new
     * @return list<array{start: ?float, end: ?float, text: string}>
     */
    public function keepTimes(array $old, array $new): array
    {
        $key = fn(string $text) => $this->words($text) === [] ? $text : implode(' ', $this->words($text));
        $pairs = $this->match(
            array_map(fn(string $text) => $key($text), $new),
            array_map(fn(array $line) => $key((string) $line['text']), $old),
        );
        $matched = array_column($pairs, 1, 0);

        return array_map(fn(string $text, int $i) => [
            'start' => isset($matched[$i]) ? $old[$matched[$i]]['start'] ?? null : null,
            'end' => isset($matched[$i]) ? $old[$matched[$i]]['end'] ?? null : null,
            'text' => $text,
        ], $new, array_keys($new));
    }

    /**
     * Index pairs of equal items, in order, covering as many items as possible.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: int, 1: int}>
     */
    protected function match(array $a, array $b): array
    {
        $a = array_slice($a, 0, self::MAX_WORDS);
        $b = array_slice($b, 0, self::MAX_WORDS);
        $n = count($a);
        $m = count($b);

        // Longest common subsequence, filled from the end so pairs come out in order.
        $next = array_fill(0, $m + 1, 0);
        $steps = [];

        for ($i = $n - 1; $i >= 0; $i--) {
            $row = array_fill(0, $m + 1, 0);
            $step = str_repeat('0', $m);

            for ($j = $m - 1; $j >= 0; $j--) {
                if ($a[$i] === $b[$j]) {
                    $row[$j] = $next[$j + 1] + 1;
                    $step[$j] = 'd';
                } elseif ($next[$j] >= $row[$j + 1]) {
                    $row[$j] = $next[$j];
                    $step[$j] = 'a';
                } else {
                    $row[$j] = $row[$j + 1];
                    $step[$j] = 'b';
                }
            }

            $steps[$i] = $step;
            $next = $row;
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            match ($steps[$i][$j]) {
                'd' => $pairs[] = [$i++, $j++],
                'a' => $i++,
                default => $j++,
            };
        }

        return $pairs;
    }

    /**
     * @param  list<array{text: string}>  $lines
     * @return list<array{word: string, line: int}>
     */
    protected function writtenWords(array $lines): array
    {
        $words = [];

        foreach ($lines as $i => $line) {
            foreach ($this->words((string) $line['text']) as $word) {
                $words[] = ['word' => $word, 'line' => $i];
            }
        }

        return $words;
    }

    /**
     * Words heard, each timed by spreading its line's time across the line's letters.
     *
     * @param  list<array{start: float, end: float, text: string}>  $lines
     * @return list<array{word: string, start: float, end: float}>
     */
    protected function heardWords(array $lines): array
    {
        $words = [];

        foreach ($lines as $line) {
            $list = $this->words((string) $line['text']);
            $letters = max(1, array_sum(array_map('mb_strlen', $list)));
            $span = max(0, (float) $line['end'] - (float) $line['start']);
            $at = (float) $line['start'];

            foreach ($list as $word) {
                $length = $span * mb_strlen($word) / $letters;
                $words[] = ['word' => $word, 'start' => $at, 'end' => $at + $length];
                $at += $length;
            }
        }

        return $words;
    }

    /**
     * Lowercase words without punctuation.
     *
     * @return list<string>
     */
    protected function words(string $text): array
    {
        preg_match_all("/[\\p{L}\\p{N}']+/u", mb_strtolower($text), $found);

        return array_values(array_filter(array_map(fn(string $word) => trim($word, "'"), $found[0])));
    }
}
