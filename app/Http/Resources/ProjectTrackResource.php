<?php

namespace App\Http\Resources;

use App\Services\Studio\Mix\MixRenderer;
use App\Services\Studio\MixPreview;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

class ProjectTrackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))
            ->remove(' ')
            ->explode(',');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'genre' => $this->when($with->contains('genre'), fn() => new GenreResource($this->genre), new \stdClass),
            'artist' => $this->when(
                ($with->contains('artist') || $with->contains('artists')) && $this->artist,
                fn() => new ArtistResource($this->artist),
                new \stdClass
            ),
            'preview' => $this->mediaFileInfo['preview']['dynamicLink'],
            'privacy' => $this->privacy,
            'duration' => $this->duration,
            'userId' => $this->user_id,
            'genreId' => $this->genre_id,
            'artistId' => $this->artist_id,
            'stemsCount' => $this->stems->count(),
            'projectId' => $this->project_id,
            'description' => $this->description,
            // The studio's tempo and meter, for the beat grid and metronome.
            'bpm' => (float) ($this->meta['bpm'] ?? 120),
            'timeSignature' => (string) ($this->meta['timeSignature'] ?? '4/4'),
            'tags' => $this->audioTags(),
            // The studio's master volume, 0 to 1, and where the server is with the preview mix.
            'mixVolume' => MixRenderer::masterVolume($this->resource),
            'mix' => MixPreview::state($this->resource),
        ];
    }
}
