<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))->remove(' ')->explode(',');

        return [
            'id' => $this->id,
            'cid' => $this->commentable_id,
            'type' => $this->commentable instanceof Comment ? 'reply' : 'comment',
            'user' => $this->when($with->contains('user'), fn() => new UserResource($this->commentator)),
            'userId' => $this->user_id,
            'comment' => $this->comment,
            'createdAt' => $this->created_at,
            'isApproved' => $this->is_approved,
            'repliesCount' => $this->comments()->count(),
        ];
    }
}
