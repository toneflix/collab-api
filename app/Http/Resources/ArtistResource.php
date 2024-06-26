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
            'about' => $this->about,
            'fullname' => $this->name,
            'verified' => $this->verified,
            'username' => $this->username,
            'imageUrl' => $this->images['image'],
            'followers' => $this->followers,
            'following' => $this->following,
            'youFollow' => $this->youFollow,
            'followsYou' => $this->followsYou,
            'reputation' => $this->reputation,
            'projects_count' => $this->projects_count,
        ];
    }
}
