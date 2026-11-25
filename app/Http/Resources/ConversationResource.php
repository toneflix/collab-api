<?php

namespace App\Http\Resources;

use App\Services\Conversations\Conversations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A conversation, read from the project, project track, track or release it
 * is about, with its latest message and the viewer's unread count.
 *
 * @mixin Model
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');
        $last = $this->resource->relationLoaded('latestMessage') ? $this->resource->getRelation('latestMessage') : null;

        return [
            ...Conversations::subject($this->resource),
            'lastMessage' => $last ? [
                'id' => $last->id,
                'body' => Conversations::preview($last, 120),
                'user' => Conversations::person($last->commentator),
                'createdAt' => $last->created_at,
            ] : null,
            'unread' => $this->resource->getAttribute('unread_count') ?? Conversations::unreadCount($this->resource, $user),
            'updatedAt' => $last?->created_at,
            'canDeleteAny' => Conversations::ownedBy($this->resource, $user),
        ];
    }
}
