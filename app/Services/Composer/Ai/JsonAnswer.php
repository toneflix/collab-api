<?php

namespace App\Services\Composer\Ai;

use RuntimeException;

/**
 * Reads the JSON object a writing model was asked to answer with.
 */
class JsonAnswer
{
    /**
     * The first JSON object in the answer, inside a ```json fence or not.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $answer): array
    {
        $json = str($answer)
            ->after('```json')
            ->before('```')
            ->trim()
            ->toString();
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($json, $start, $end - $start + 1), true) : null;

        if (! is_array($data) && $start !== false && substr_count($json, '{') > substr_count($json, '}')) {
            throw new RuntimeException('The AI answer was cut off before it finished. Try again, or ask for less in one run.');
        }

        if (! is_array($data)) {
            throw new RuntimeException('The AI answer wasn\'t valid JSON.');
        }

        return $data;
    }
}
