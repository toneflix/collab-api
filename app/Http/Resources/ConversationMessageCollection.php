<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

/**
 * A page of a conversation's messages. Pages run newest first, and each
 * page lists its messages oldest first, as the chat shows them.
 */
class ConversationMessageCollection extends ResourceCollection
{
    public $collects = ConversationMessageResource::class;

    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->reverse()->values()->map->toArray($request)->all();
    }

    /**
     * Cursor pages carry their cursors in `meta`.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $default['meta'] = [
            'path' => $paginated['path'] ?? null,
            'per_page' => $paginated['per_page'] ?? null,
            'next_cursor' => $paginated['next_cursor'] ?? null,
            'prev_cursor' => $paginated['prev_cursor'] ?? null,
        ];

        return $default;
    }
}
