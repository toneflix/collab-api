<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReleaseResource extends JsonResource
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
            'draft' => $this->draft,
            'genre' => $this->when($with->contains('genre'), fn () => new GenreResource($this->genre)),
            'tracks' => $this->when($with->contains('tracks'), fn () => new TrackCollection($this->tracks)),
            'artist' => $this->when(
                $with->contains('artist') && $this->artist,
                fn () => new ArtistResource($this->artist),
                new \stdClass()
            ),
            'privacy' => $this->privacy,
            'streams' => $this->streams,
            'imageUrl' => $this->files['artwork'],
            'genre_id' => $this->genre_id,
            'duration' => $this->duration,
            'description' => $this->description,
            'tracks_count' => $this->tracks->count(),
            'isrc' => $this->isrc,
            'iswc' => $this->iswc,
            'pline' => $this->pline,
            'label' => $this->record_label,
            'record_label' => $this->record_label,
            'explicit' => $this->explicit,
            'composer' => $this->composer,
            'publisher' => $this->publisher,
            'artist_id' => $this->artist_id,
            'contains_music' => $this->contains_music,
            'releaseDate' => $this->release_date?->format('Y/m/d'),
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
