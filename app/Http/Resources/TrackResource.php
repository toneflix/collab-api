<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackResource extends JsonResource
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
            'loved' => $this->loved,
            'loves' => $this->loves,
            'genre' => $this->when($with->contains('genre'), fn () => new GenreResource($this->genre)),
            'release' => $this->when($with->contains('release') && !$with->contains('tracks'), fn () => new ReleaseResource($this->release)),
            'artist' => $this->when(
                $with->contains('artist') && $this->artist,
                fn () => new ArtistResource($this->artist),
                new \stdClass()
            ),
            'privacy' => $this->privacy,
            'artist_id' => $this->artist?->id,
            'streams' => $this->streams,
            'imageUrl' => $this->files['image'],
            'streamUrl' => $this->files['audio'],
            'genre_id' => $this->genre_id,
            'duration' => $this->duration,
            'description' => $this->description,
            'explicit' => $this->explicit,
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
