<?php

namespace App\Services\Explorers;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * One explore section: a page of items as `list` and its pagination `meta`.
 */
class Section
{
    /**
     * @return array{list: array<int, mixed>, meta: array<string, mixed>}
     */
    public static function from(ResourceCollection $collection): array
    {
        $body = $collection
            ->response()
            ->getData(true);

        return [
            'list' => $body['data'],
            'meta' => $body['meta'] ?? [],
        ];
    }
}
