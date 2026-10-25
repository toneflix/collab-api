<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A word from a Composer lookup, with its syllable count when known.
 */
class WordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $word = (array) $this->resource;

        return array_filter([
            'word' => $word['word'],
            'syllables' => $word['syllables'] ?? null,
            'score' => $word['score'] ?? null,
            'stress' => $word['stress'] ?? null,
            'known' => $word['known'] ?? null,
        ], fn($value) => $value !== null);
    }
}
