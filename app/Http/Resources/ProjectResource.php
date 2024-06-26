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

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'ready' => $this->ready,
            'props' => $this->props,
            'group' => $this->group,
            'tracks' => $this->when($with->contains('tracks'), fn () => new ProjectTrackCollection($this->tracks)),
            'proped' => $this->proped,
            'privacy' => $this->privacy,
            'imageUrl' => $this->images['image'],
            'description' => $this->description,
            'tracksCount' => $this->tracks()->count(),
            'collaboratorsCount' => $this->collaborators()->count(),
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}