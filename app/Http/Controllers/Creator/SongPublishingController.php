<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Services\Composer\SongPublishing;
use Illuminate\Http\Request;

/**
 * A writer's publishing settings and artwork for a song.
 */
class SongPublishingController extends Controller
{
    public function update(Request $request, SongPublishing $publishing, Song $song)
    {
        $this->authorize('update', $song);

        $data = $request->validate([
            'published' => ['sometimes', 'boolean'],
            'allow_forks' => ['sometimes', 'boolean'],
            'allow_projects' => ['sometimes', 'boolean'],
        ]);

        $was = $song->isPublished();
        $song = $publishing->update($song, $data);
        $song->load(['beat', 'track.project', 'forkedFrom']);

        $message = match (true) {
            ! $was && $song->isPublished() => 'Composition published.',
            $was && ! $song->isPublished() => 'Composition unpublished.',
            default => 'Publishing settings saved.',
        };

        return (new SongResource($song))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
