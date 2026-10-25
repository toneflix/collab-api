<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A studio effect written in Faust, with its source for the browser to
 * compile and the settings it takes.
 */
class FaustEffectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'name' => $this->resource['name'],
            'label' => $this->resource['label'],
            'group' => $this->resource['group'],
            'description' => $this->resource['description'],
            'source' => $this->resource['source'],
            'params' => $this->resource['params'],
        ];
    }
}
