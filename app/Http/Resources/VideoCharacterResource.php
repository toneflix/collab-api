<?php

namespace App\Http\Resources;

use App\Models\VideoCharacter;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A saved character with a link to its sheet.
 *
 * @mixin VideoCharacter
 */
class VideoCharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'uploaded' => $this->uploaded,
            'sheetUrl' => app(VideoStorage::class)->savedSheetUrl($this->resource),
            'createdAt' => $this->created_at,
        ];
    }
}
