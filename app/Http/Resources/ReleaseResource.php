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

        $user = $request->user('sanctum');
        $interaction = $this->socialInteractionData($user);

        $imageUrl = $this->files['artwork'];
        if (! $this->image && isset($this->project)) {
            $imageUrl = $this->project->files['image'];
        }

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'draft' => $this->draft,
            'version' => $this->version,
            'retired' => $this->retired_at !== null,
            'retiredAt' => $this->retired_at,
            'reviewStatus' => $this->review_status,
            'reviewNote' => $this->when((bool) $user && (int) $user->id === (int) $this->user_id, $this->review_note),
            'genre' => $this->when($with->contains('genre'), fn () => new GenreResource($this->genre)),
            'tracks' => $this->when($with->contains('tracks'), fn () => new TrackCollection($this->tracks)),
            'artist' => $this->when($with->contains('artist'), [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'fullname' => $this->artist->name,
                'username' => $this->artist->username,
                'imageUrl' => $this->artist->images['image'],
            ]),
            'project' => $this->when(
                (bool) $this->project && $with->contains('project'),
                fn () => new ProjectResource($this->project),
            ),
            'privacy' => $this->privacy,
            'takenDown' => $this->taken_down_at !== null,
            'price' => $this->price,
            'locked' => ! $this->isUnlockedFor($user),
            'purchased' => $this->price > 0 && $this->isPurchasedBy($user, 'release'),
            'streams' => $this->streams,
            'imageUrl' => $imageUrl,
            'genre_id' => $this->genre_id,
            'duration' => $this->duration ?? 0,
            'description' => $this->description,
            $this->mergeWhen($with->contains('interaction'), fn () => [
                'interaction' => $interaction,
                'liked' => $interaction['liked'] ?? false,
                'likes' => $interaction['likes'] ?? 0,
            ], [
                'liked' => $interaction['liked'] ?? false,
                'likes' => $interaction['likes'] ?? 0,
            ]),
            'tracksCount' => $this->tracks_count,
            'commentsCount' => $this->when($with->contains('comments_count'), fn () => $this->comments()->count()),
            'isrc' => $this->isrc,
            'iswc' => $this->iswc,
            'pline' => $this->pline,
            'label' => $this->record_label,
            'explicit' => $this->explicit,
            'composer' => $this->composer,
            'publisher' => $this->publisher,
            'artist_id' => $this->artist_id,
            'project_id' => $this->project_id,
            'record_label' => $this->record_label,
            'contains_music' => $this->contains_music,
            'releaseDate' => $this->release_date?->format('Y/m/d'),
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
