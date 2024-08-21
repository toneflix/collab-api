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
        $user = $request->user('sanctum');
        $with = str($request->input('with'))->remove(' ')->explode(',');
        $interaction = $this->socialInteractionData($user);

        $imageUrl = $this->files['image'];
        if (!$this->image && isset($this->projectTrack->project)) {
            $imageUrl = $this->projectTrack->project->files['image'];
        }

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
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
            $this->mergeWhen($with->contains('interaction'), fn () => [
                'interaction' => $interaction,
                'liked' => $interaction['liked'] ?? false,
                'likes' => $interaction['likes'] ?? 0,
            ], [
                'liked' => $interaction['liked'] ?? false,
                'likes' => $interaction['likes'] ?? 0,
            ]),
            'format' => $this->meta['format'] ?? 'mp3',
            'privacy' => $this->privacy,
            'streams' => $this->streams,
            'genre_id' => $this->genre_id,
            'duration' => $this->duration,
            'imageUrl' => $imageUrl,
            'artist_id' => $this->artist?->id,
            'streamUrl' => $this->when(
                $with->contains('streamUrl') || $user?->id === $this->user_id,
                function () {
                    $streamUrl = $this->getFiles['audio']['dynamicLink'];
                    if ($streamUrl === '' && isset($this->projectTrack)) {
                        $streamUrl = $this->projectTrack->getFiles['preview']['dynamicLink'];
                    }
                    return $streamUrl;
                }
            ),
            'explicit' => $this->explicit,
            'description' => $this->description,
            'audioMissing' => !$this->audio,
            'commentsCount' => $this->when($with->contains('comments_count'), fn () => $this->comments()->count()),
            'project_track_id' => $this->projectTrack?->id,
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
