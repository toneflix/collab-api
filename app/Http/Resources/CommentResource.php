<?php

namespace App\Http\Resources;

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
            'comment' => $this->comment,
            'isApproved' => $this->is_approved,
            'userId' => $this->user_id,
            'user' => $this->when($with->contains('user'), fn () => new UserResource($this->commentator)),
            'createdAt' => $this->created_at,
            'repliesCount' => $this->comments()->count(),
        ];
    }
}
