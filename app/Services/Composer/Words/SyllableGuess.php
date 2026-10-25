<?php

namespace App\Services\Composer\Words;

/**
 * Counts syllables from spelling, for slang and names the dictionary lacks.
 */
class SyllableGuess
{
    public static function count(string $word): int
    {
        $letters = preg_replace('/[^a-z]/', '', mb_strtolower($word));

        if ($letters === '') {
            return 0;
        }

        $count = preg_match_all('/[aeiouy]+/', $letters);

        // A final silent "e" (rate, home) is not a syllable, but "-le" after a consonant (table) is.
        if (preg_match('/[^aeiouy]e$/', $letters) && ! preg_match('/[^aeiouy]le$/', $letters)) {
            $count--;
        }

        // "-es" and "-ed" endings are usually silent (makes, played) unless after t, d, s or z sounds.
        if (preg_match('/[^aeiouytdszxgc][eE][sd]$/', $letters)) {
            $count--;
        }

        return max(1, $count);
    }
}
