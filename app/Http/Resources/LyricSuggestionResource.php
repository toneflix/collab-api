<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * Lyric suggestions from Composer's AI, with what they cost.
 *
 * @property array{kind: string, line: int, options: list<list<string>>, credits: int, balance: int} $resource
 */
class LyricSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->resource['kind'],
            'line' => $this->resource['line'],
            'options' => array_map(fn(array $lines) => ['lines' => $lines], $this->resource['options']),
            'credits' => $this->resource['credits'],
            'balance' => $this->resource['balance'],
        ];
    }
}
