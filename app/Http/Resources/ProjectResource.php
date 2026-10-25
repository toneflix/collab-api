<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * The most tracks or collaborators embedded with a project.
     */
    public const LIST_LIMIT = 45;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))
            ->remove(' ')
            ->explode(',');
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
                $with->contains('tracks'),
                fn() => new ProjectTrackCollection(
                    $this->tracks()
                        ->visibleTo($user)
                        ->when($with->contains('ready'), fn($q) => $q->ready())
                        ->with(['stems', ...$with->intersect(['genre', 'artist'])->all()])
                        ->limit(self::LIST_LIMIT)
                        ->get()
                )
            ),
            'collaborators' => $this->when(
                $with->contains('collaborators') && $user?->can('update', $this->resource),
                fn() => new ProjectCollaboratorCollection(
                    $this->collaborators()->with('user')->limit(self::LIST_LIMIT)->get()
                )
            ),
            'isCollaber' => $this->when($user, fn() => $this->isCollaber($user), false),
            'isOwner' => $this->when($user, fn() => $user->id === $this->user_id, false),
            'props' => $interaction['props'],
            'proped' => $interaction['proped'] ?? false,
            'privacy' => $this->privacy,
            'user_id' => $this->user_id,
            'released' => $this->released,
            'draftReleaseId' => $this->when(
                $user && $user->id === $this->user_id,
                fn() => $this->releases()->where('draft', true)->latest('id')->value('id'),
            ),
            'imageUrl' => $this->images['image'],
            'interaction' => $interaction,
            'pendingData' => $this->when(
                $user && $with->contains('pending_data') && ($clbd = $this->collaberData($user, true)),
                fn() => new ProjectCollaboratorResource($clbd)
            ),
            'description' => $this->description,
            'tracksCount' => $this->tracks_count,
            'commentsCount' => $this->when($with->contains('comments_count'), fn() => $this->comments()->count()),
            // 'commentsCount' => $this->comments()->count(),
            'collaboratorsCount' => $this->collaborators_count,
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
