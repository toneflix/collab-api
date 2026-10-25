<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\DefinitionCollection;
use App\Http\Resources\WordCollection;
use App\Services\Composer\Words\Words;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Word completion, rhymes, related words, definitions and syllable counts
 * for Composer.
 */
class WordController extends Controller
{
    public function complete(Request $request, Words $words)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:40'],
        ]);

        $results = $words->complete($data['q'], $request->perPage(10, 25));

        return (new WordCollection($results))->additional($this->envelope($words));
    }

    public function rhymes(Request $request, Words $words, string $word)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(Words::RHYME_TYPES)],
        ]);

        $results = $words->rhymes($word, $data['type'] ?? 'perfect', $request->perPage(60, 100));

        return (new WordCollection($results))->additional($this->envelope($words));
    }

    public function related(Request $request, Words $words, string $word)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(Words::RELATED_TYPES)],
        ]);

        $results = $words->related($word, $data['type'] ?? 'means', $request->perPage(60, 100));

        return (new WordCollection($results))->additional($this->envelope($words));
    }

    public function definitions(Words $words, string $word)
    {
        $results = $words->definitions($word);

        return (new DefinitionCollection($results))->additional($this->envelope($words));
    }

    public function syllables(Request $request, Words $words)
    {
        $data = $request->validate([
            'words' => ['required', 'array', 'max:200'],
            'words.*' => ['string', 'max:40'],
        ]);

        $results = $words->syllables($data['words']);

        return (new WordCollection($results))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function envelope(Words $words): array
    {
        return [
            'source' => $words->source()->name(),
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ];
    }
}
