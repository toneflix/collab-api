<?php

namespace App\Http\Resources;

use App\Services\Artists\ArtistClaims;
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
        $viewer = $request->user('sanctum');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => 'artist',
            'about' => $this->about,
            'fullname' => $this->name,
            'verified' => $this->verified,
            'username' => $this->username,
            'imageUrl' => $this->images['image'],
            'coverUrl' => $this->cover ? $this->images['cover'] : null,
            'followers' => $this->whenLoaded(
                'followers',
                fn () => $this->followers->count(),
                fn () => $this->followers()->count(),
            ),
            'following' => 0,
            'youFollow' => $viewer ? $this->isFollowedBy($viewer) : false,
            'followsYou' => false,
            'reputation' => $this->reputation,
            'projectsCount' => 0,
            'canEdit' => (bool) $viewer?->can('update', $this->resource),
            'canVerify' => (bool) $viewer?->can('verify', $this->resource),
            'claimable' => $this->isClaimable(),
            'yourClaim' => $this->when(
                $request->routeIs('artists.show') && $this->isClaimable(),
                fn () => ($claim = app(ArtistClaims::class)->latestFor($viewer, $this->resource))
                    ? (new ArtistClaimResource($claim))->resolve($request)
                    : null,
            ),
        ];
    }
}
