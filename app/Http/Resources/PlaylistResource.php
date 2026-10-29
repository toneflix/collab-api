<?php

namespace App\Http\Resources;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin Playlist
 */
class PlaylistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'privacy' => $this->privacy,
            'tracksCount' => (int) ($this->tracks_count ?? 0),
            'imageUrl' => $this->coverTrack?->artworkUrl(),
            'owner' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
                'username' => $this->user->username,
                'imageUrl' => $this->user->images['image'],
            ]),
            'owned' => $this->isOwnedBy($request->user('sanctum')),
            'hasTrack' => $this->when(isset($this->has_track), fn() => (bool) $this->has_track),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
