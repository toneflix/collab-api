<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))->remove(' ')->explode(',');
        $user = $request->user('sanctum');
        $interaction = $this->socialInteractionData($request->user('sanctum'));

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'ready' => $this->ready,
            'props' => $this->props,
            'group' => $this->group,
            'tracks' => $this->when(
                $with->contains('tracks') && ! $with->contains('ready'),
                fn () => new ProjectTrackCollection($this->tracks)
            ),
            'tracks' => $this->when(
                $with->contains('tracks') && $with->contains('ready'),
                fn () => new ProjectTrackCollection($this->tracksReady)
            ),
            'isCollaber' => $this->when($user, fn () => $this->isCollaber($user)),
            'isOwner' => $this->when($user, fn () => $user->id === $this->user_id),
            'props' => $interaction['props'],
            'proped' => $interaction['proped'] ?? false,
            'privacy' => $this->privacy,
            'user_id' => $this->user_id,
            'released' => $this->released,
            'imageUrl' => $this->images['image'],
            'interaction' => $interaction,
            'description' => $this->description,
            'tracksCount' => $this->tracks_count,
            'commentsCount' => $this->when($with->contains('comments_count'), fn () => $this->comments()->count()),
            // 'commentsCount' => $this->comments()->count(),
            'collaboratorsCount' => $this->collaborators_count,
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
