<?php

namespace App\Services\Composer\Words;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Lookups answered by the Datamuse API, called from the server only.
 *
 * @see https://www.datamuse.com/api/
 */
class DatamuseWords implements WordSource
{
    protected const RHYMES = [
        'perfect' => 'rel_rhy',
        'near' => 'rel_nry',
        'sounds' => 'sl',
    ];

    protected const RELATED = [
        'means' => 'ml',
        'follows' => 'lc',
        'triggers' => 'rel_trg',
    ];

    public function name(): string
    {
        return 'datamuse';
    }

    public function complete(string $prefix, int $max): array
    {
        return $this->rows($this->get('/sug', ['s' => $prefix, 'max' => $max]));
    }

    public function rhymes(string $word, string $type, int $max): array
    {
        $param = self::RHYMES[$type] ?? self::RHYMES['perfect'];

        return $this->rows($this->get('/words', [$param => $word, 'max' => $max, 'md' => 's']));
    }

    public function related(string $word, string $type, int $max): array
    {
        $param = self::RELATED[$type] ?? self::RELATED['means'];

        return $this->rows($this->get('/words', [$param => $word, 'max' => $max, 'md' => 's']));
    }

    public function definitions(string $word): array
    {
        $entry = collect($this->get('/words', ['sp' => $word, 'md' => 'd', 'max' => 1]))
            ->first(fn($entry) => mb_strtolower($entry['word'] ?? '') === mb_strtolower($word));

        // Each definition reads "n\tthe act of touching".
        return collect($entry['defs'] ?? [])
            ->map(function (string $definition) {
                [$pos, $text] = str_contains($definition, "\t") ? explode("\t", $definition, 2) : [null, $definition];

                return ['partOfSpeech' => $this->partOfSpeech($pos), 'text' => trim($text)];
            })
            ->filter(fn(array $definition) => $definition['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function get(string $path, array $query): array
    {
        try {
            $response = $this
                ->http()
                ->get($path, array_filter($query + ['key' => config('services.datamuse.key')]));
        } catch (ConnectionException $e) {
            throw new WordSourceUnavailable('Datamuse could not be reached: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new WordSourceUnavailable("Datamuse answered {$response->status()}.");
        }

        return $response->json();
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.datamuse.base_url'), '/'))
            ->acceptJson()
            ->timeout(5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return list<array{word: string, syllables: ?int, score: ?int}>
     */
    protected function rows(array $entries): array
    {
        return collect($entries)
            ->filter(fn($entry) => is_array($entry) && filled($entry['word'] ?? null))
            ->map(fn(array $entry) => [
                'word' => (string) $entry['word'],
                'syllables' => isset($entry['numSyllables']) ? (int) $entry['numSyllables'] : null,
                'score' => isset($entry['score']) ? (int) $entry['score'] : null,
            ])
            ->values()
            ->all();
    }

    protected function partOfSpeech(?string $tag): ?string
    {
        return match ($tag) {
            'n' => 'noun',
            'v' => 'verb',
            'adj' => 'adjective',
            'adv' => 'adverb',
            default => $tag,
        };
    }
}
