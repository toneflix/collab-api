<?php

namespace App\Http\Resources;

use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A music video with its status, cost basis and, when loaded, its shots.
 *
 * @mixin MusicVideo
 */
class MusicVideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trackId' => $this->track_id,
            'track' => $this->whenLoaded('track', fn() => [
                'id' => $this->track->id,
                'slug' => $this->track->slug,
                'title' => $this->track->title,
            ]),
            'status' => $this->status,
            'ratio' => $this->ratio,
            'brief' => $this->brief,
            'hasPortrait' => (bool) $this->portrait,
            'duration' => $this->duration,
            'minutes' => $this->startedMinutes(),
            'billing' => $this->billing,
            'videoUrl' => app(VideoStorage::class)->videoUrl($this->resource),
            'error' => $this->error,
            'edited' => $this->edited(),
            'characters' => $this->whenLoaded('characters', fn() => $this->characters
                ->map(fn(MusicVideoCharacter $character) => [
                    'id' => $character->id,
                    'key' => $character->key,
                    'name' => $character->name,
                    'description' => $character->description,
                    'singer' => $character->singer,
                    'provided' => $character->provided,
                    'sheetUrl' => app(VideoStorage::class)->sheetUrl($character),
                ])
                ->values()
                ->all()),
            'shots' => $this->whenLoaded('shots', fn() => $this->shots
                ->map(fn(MusicVideoShot $shot) => (new MusicVideoShotResource($shot))->resolve($request))
                ->values()
                ->all()),
            'takes' => $this->whenLoaded('takes', fn() => $this->takes
                ->sortByDesc('id')
                ->map(fn(MusicVideoTake $take) => [
                    'id' => $take->id,
                    'shotId' => $take->shot_id,
                    'source' => $take->source,
                    'length' => $take->length,
                    'synced' => $take->synced(),
                    'prompt' => $take->prompt,
                    'clipUrl' => app(VideoStorage::class)->takeUrl($take),
                    'createdAt' => $take->created_at,
                ])
                ->values()
                ->all()),
            'createdAt' => $this->created_at,
            'finishedAt' => $this->finished_at,
        ];
    }
}
