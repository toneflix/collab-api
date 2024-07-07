<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTrackStemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))->remove(' ')->explode(',');

        $this->meta['src'] = $this->verifyAccess($request->user())
            ? $this->mediaFileInfo['src']['dynamicLink']
            : $this->mediaFileInfo['src']['secureLink'];

        return [
            'id' => $this->id,
            'src' => $this->src,
            'size' => $this->mediaFileInfo['src']['size'],
            'name' => $this->meta['name'] ?? '',
            'title' => $this->title,
            'stem_id' => $this->stem_id,
            'track_id' => $this->track_id,
            'meta' => $this->meta,
            'stem' => $this->when($with->contains('stem'), fn () => new static($this->stem)),
            'track' => $this->when($with->contains('track'), fn () => new TrackResource($this->track), new \stdClass()),
            'updatesAt' => $this->updated_at,
            'createdAt' => $this->created_at,
        ];
    }
}
