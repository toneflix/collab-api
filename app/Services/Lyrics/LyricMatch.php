<?php

namespace App\Services\Lyrics;

use App\Models\Lyric;

/**
 * Picks the line of some lyrics that best matches a search, to show why a
 * track turned up.
 */
class LyricMatch
{
    public static function line(Lyric $lyric, string $search): ?string
    {
        $needle = mb_strtolower(trim($search));
        $words = array_filter(preg_split('/\s+/u', $needle) ?: [], fn (string $word) => mb_strlen($word) > 2);

        $best = null;
        $bestScore = 0;

        foreach ($lyric->lines as $line) {
            $text = mb_strtolower($line['text']);
            $score = str_contains($text, $needle) ? PHP_INT_MAX : count(array_filter($words, fn (string $word) => str_contains($text, $word)));

            if ($score > $bestScore) {
                [$best, $bestScore] = [$line['text'], $score];
            }
        }

        return $best;
    }
}
