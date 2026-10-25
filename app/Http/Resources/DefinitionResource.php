<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * One meaning of a word.
 */
class DefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = (array) $this->resource;

        return [
            'partOfSpeech' => $definition['partOfSpeech'] ?? null,
            'text' => $definition['text'],
        ];
    }
}
