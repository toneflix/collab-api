<?php

namespace App\Http\Resources;

use App\Services\Lyrics\LyricMatch;
use App\Services\Releases\Downloads;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

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
        $with = str($request->input('with'))
            ->remove(' ')
            ->explode(',');
        $interaction = $this->socialInteractionData($user);
        $locked = ! $this->isUnlockedFor($user);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'genre' => $this->when($with->contains('genre'), fn() => new GenreResource($this->genre)),
            'release' => $this->when(
                $with->contains('release') && ! $with->contains('tracks'),
                fn() => new ReleaseResource($this->release)
            ),
            'album' => $this->when($this->inAlbum(), fn() => [
                'id' => $this->release->id,
                'slug' => $this->release->slug,
                'title' => $this->release->title,
            ]),
            'artist' => $this->when($with->contains('artist') && $this->artist, fn() => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
                'fullname' => $this->artist->name,
                'username' => $this->artist->username,
                'imageUrl' => $this->artist->images['image'],
            ]),
            $this->mergeWhen($with->contains('interaction'), fn() => [
                'interaction' => $interaction,
                'liked' => $interaction['liked'] ?? false,
                'likes' => $interaction['likes'] ?? 0,
                'saved' => $interaction['saved'] ?? false,
            ], [
                'liked' => $interaction['liked'] ?? false,
                'likes' => $interaction['likes'] ?? 0,
                'saved' => $interaction['saved'] ?? false,
            ]),
            'format' => $this->meta['format'] ?? 'mp3',
            'privacy' => $this->privacy,
            'takenDown' => $this->taken_down_at !== null,
            'streams' => $this->streams,
            'genreId' => $this->genre_id,
            'duration' => $this->duration,
            'imageUrl' => $this->artworkUrl(),
            'artistId' => $this->artist?->id,
            'price' => (float) ($this->release?->price ?? 0),
            'locked' => $locked,
            'downloadable' => Downloads::available($this->resource, $user),
            'stemsPrice' => $this->stems_price,
            'stemsForSale' => $this->when($with->contains('stems'), fn() => $this->stemsForSale()),
            'stemsUnlocked' => $this->when($with->contains('stems'), fn() => $this->stemsUnlockedFor($user)),
            // Paid tracks only stream for their owner and people who bought the release.
            'streamUrl' => $this->when(
                ! $locked && ($with->contains('streamUrl') || $this->release?->isOwnedBy($user)),
                function () {
                    $streamUrl = $this->getFiles['audio']['dynamicLink'];
                    if ($streamUrl === '' && isset($this->projectTrack)) {
                        $streamUrl = $this->projectTrack->getFiles['preview']['dynamicLink'];
                    }

                    return $streamUrl;
                }
            ),
            'explicit' => (bool) $this->explicit || (bool) $this->release?->explicit,
            'tags' => $this->audioTags(),
            'owned' => (bool) $this->release?->isOwnedBy($user),
            'hasLyrics' => $this->when($this->relationLoaded('lyric'), fn() => $this->lyric !== null),
            'lyricMatch' => $this->when(
                $request->filled('search') && $this->relationLoaded('lyric'),
                fn() => $this->lyric ? LyricMatch::line($this->lyric, (string) $request->search) : null
            ),
            'description' => $this->description,
            'audioMissing' => ! $this->audio,
            'commentsCount' => $this->when($with->contains('comments_count'), fn() => $this->comments()->count()),
            'projectTrackId' => $this->projectTrack?->id,
            'updatedAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
