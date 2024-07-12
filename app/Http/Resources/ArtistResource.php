<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtistResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => 'artist',
            'about' => $this->about,
            'fullname' => $this->name,
            'verified' => $this->verified,
            'username' => $this->username,
            'imageUrl' => $this->images['image'],
            'followers' => $this->whenLoaded(
                'followers',
                fn () => $this->followers->count(),
                fn () => $this->followers()->count(),
            ),
            'following' => 0,
            'youFollow' => $request->user('sanctum') ? $this->isFollowedBy($request->user('sanctum')) : false,
            'followsYou' => false,
            'reputation' => $this->reputation,
            'projectsCount' => 0,
        ];
    }
}
