<?php

namespace App\Http\Resources;

use App\Models\ArtistClaim;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin ArtistClaim
 */
class ArtistClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'role' => $this->role,
            'name' => $this->name,
            'email' => $this->email,
            'links' => $this->links,
            'message' => $this->message,
            'hasDocument' => (bool) $this->document,
            'reviewNote' => $this->review_note,
            'reviewedAt' => $this->reviewed_at,
            'createdAt' => $this->created_at,
            'artist' => $this->whenLoaded('artist', fn() => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'username' => $this->artist->username,
                'imageUrl' => $this->artist->images['image'],
                'claimable' => $this->artist->isClaimable(),
                'unownedReleases' => $this->artist->unowned_releases_count,
            ]),
            'user' => $this->whenLoaded('user', fn() => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
                'username' => $this->user->username,
                'email' => $this->user->email,
                'imageUrl' => $this->user->images['image'],
            ] : null),
            'reviewer' => $this->when(
                $this->relationLoaded('reviewer'),
                fn() => $this->reviewer?->fullname ?? ($this->reviewed_at ? 'AI moderator' : null),
            ),
            'aiReview' => $this->whenLoaded('latestAiReview', fn() => $this->latestAiReview ? (new AiReviewResource($this->latestAiReview))->resolve($request) : null),
        ];
    }
}
