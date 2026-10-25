<?php

namespace App\Services\Lyrics;

use App\Services\Ai\AiSettings;

/**
 * Looks through lyrics for explicit words, which mark a track explicit, and
 * for words a moderator should check.
 */
class LyricsCheck
{
    /**
     * @return array{explicit: list<string>, review: list<string>}
     */
    public function scan(string $text): array
    {
        $normalized = ' '.implode(' ', $this->words($text)).' ';

        return [
            'explicit' => $this->found($normalized, 'explicit'),
            'review' => $this->found($normalized, 'review'),
        ];
    }

    /**
     * Each entry of a list that appears in the text as whole words.
     *
     * @return list<string>
     */
    protected function found(string $normalized, string $list): array
    {
        return collect([...(array) config("lyrics.{$list}", []), ...AiSettings::lyricsWords($list)])
            ->map(fn (string $entry) => implode(' ', $this->words($entry)))
            ->filter()
            ->unique()
            ->filter(fn (string $entry) => str_contains($normalized, " {$entry} "))
            ->values()
            ->all();
    }

    /**
     * The text's words in lowercase, without punctuation or apostrophes.
     *
     * @return list<string>
     */
    protected function words(string $text): array
    {
        $text = mb_strtolower(str_replace(['’', "'"], '', $text));

        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
