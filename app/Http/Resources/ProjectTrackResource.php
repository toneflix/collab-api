<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTrackResource extends JsonResource
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
            'genre' => $this->when($with->contains('genre'), fn () => new GenreResource($this->genre), new \stdClass),
            'artist' => $this->when(
                ($with->contains('artist') || $with->contains('artists')) && $this->artist,
                fn () => new ArtistResource($this->artist),
                new \stdClass
            ),
            'preview' => $this->mediaFileInfo['preview']['dynamicLink'],
            'privacy' => $this->privacy,
            'duration' => $this->duration,
            'user_id' => $this->user_id,
            'genre_id' => $this->genre_id,
            'artist_id' => $this->artist_id,
            'stemsCount' => $this->stems->count(),
            'project_id' => $this->project_id,
            'description' => $this->description,
        ];
    }
}
