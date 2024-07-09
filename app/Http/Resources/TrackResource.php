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
        $streamUrl = $this->getFiles['audio']['dynamicLink'];

        if ($streamUrl === '' && isset($this->projectTrack)) {
            $streamUrl = $this->projectTrack->getFiles['preview']['dynamicLink'];
        }

        $imageUrl = $this->files['image'];
        if (!$this->image && isset($this->projectTrack->project)) {
            $imageUrl = $this->projectTrack->project->files['image'];
        }

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'loved' => $this->loved,
            'loves' => $this->loves,
            'genre' => $this->when($with->contains('genre'), fn () => new GenreResource($this->genre)),
            'release' => $this->when(
                $with->contains('release') && !$with->contains('tracks'),
                fn () => new ReleaseResource($this->release)
            ),
            'artist' => $this->when($with->contains('artist') && $this->artist, fn () => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'fullname' => $this->artist->name,
                'username' => $this->artist->username,
                'imageUrl' => $this->artist->images['image'],
            ]),
            'privacy' => $this->privacy,
            'streams' => $this->streams,
            'genre_id' => $this->genre_id,
            'duration' => $this->duration,
            'imageUrl' => $imageUrl,
            'artist_id' => $this->artist?->id,
            'streamUrl' => $streamUrl,
            'explicit' => $this->explicit,
            'description' => $this->description,
            'defaultStream' => !$this->audio,
            'project_track_id' => $this->projectTrack?->id,
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
