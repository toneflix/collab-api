<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Services\Conversations\Conversations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message in a conversation. Also carries the fields the older comment
 * views read (`cid`, `type`, `userId`, `comment`, `repliesCount`).
 *
 * @mixin Comment
 */
class ConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isReply = $this->commentable_type === Comment::class;
        $parent = $isReply ? $this->parent : null;

        return [
            'id' => $this->id,
            'cid' => $this->commentable_id,
            'type' => $isReply ? 'reply' : 'comment',
            'body' => $this->comment,
            'comment' => $this->comment,
            'user' => Conversations::person($this->commentator),
            'userId' => $this->user_id,
            'parent' => $parent ? [
                'id' => $parent->id,
                'body' => Conversations::preview($parent, 140),
                'user' => Conversations::person($parent->commentator),
                'region' => Conversations::region($parent),
                'hasVoice' => ! empty($parent->meta['voice']),
            ] : null,
            'voice' => Conversations::voice($this->resource),
            'region' => Conversations::region($this->resource),
            'conversation' => $this->root_type ? [
                'type' => Conversations::typeOf($this->root_type),
                'id' => $this->root_id,
            ] : null,
            'isApproved' => (bool) $this->is_approved,
            'repliesCount' => $this->replies_count ?? $this->comments()->count(),
            'createdAt' => $this->created_at,
        ];
    }
}
