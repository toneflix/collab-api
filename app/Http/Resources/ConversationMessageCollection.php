<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

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
}
