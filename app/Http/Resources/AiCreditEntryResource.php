<?php

namespace App\Http\Resources;

use App\Models\AiCreditEntry;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin AiCreditEntry
 */
class AiCreditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'credits' => $this->credits,
            'description' => $this->description,
            'createdAt' => $this->created_at,
        ];
    }
}
