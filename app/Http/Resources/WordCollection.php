<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

class WordCollection extends ResourceCollection
{
    public $collects = WordResource::class;

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
