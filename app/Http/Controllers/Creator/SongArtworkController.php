<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Services\Composer\SongArtwork;
use Illuminate\Http\Request;

/**
 * A song's artwork.
 */
class SongArtworkController extends Controller
{
    public function store(Request $request, SongArtwork $artwork, Song $song)
    {
        $this->authorize('update', $song);

        $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        $song = $artwork->set($song, $request->file('image'));
        $song->load(['beat', 'track.project', 'forkedFrom']);

        return (new SongResource($song))
            ->additional([
                'message' => 'Artwork saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(SongArtwork $artwork, Song $song)
    {
        $this->authorize('update', $song);

        $song = $artwork->remove($song);
        $song->load(['beat', 'track.project', 'forkedFrom']);

        return (new SongResource($song))
            ->additional([
                'message' => 'Artwork removed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
