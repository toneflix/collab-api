<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\TrackCollection;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\Playlists\PlaylistTracks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The tracks in a playlist: listing them in order, adding and removing.
 */
class PlaylistTracksController extends Controller
{
    /**
     * The playlist's tracks that are still out, in its order.
     */
    public function index(Request $request, Playlist $playlist)
    {
        Gate::forUser($this->user)->authorize('view', $playlist);

        $tracks = $playlist
            ->tracks()
            ->whereHas('release', fn(Builder $q) => $q->live())
            ->with('release')
            ->paginate($request->perPage(50, 100));

        return (new TrackCollection($tracks))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(Request $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        $data = $request->validate([
            'track_id' => ['required', 'integer'],
        ]);

        $track = Track::query()
            ->whereHas('release', fn(Builder $q) => $q->live())
            ->findOrFail($data['track_id']);

        $added = (new PlaylistTracks($playlist))->add($track);

        $playlist
            ->load(['user', 'coverTrack.release'])
            ->loadCount('tracks');

        return (new PlaylistResource($playlist))
            ->additional([
                'message' => $added
                    ? __('Added to :title.', ['title' => $playlist->title])
                    : __('Already in :title.', ['title' => $playlist->title]),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(Playlist $playlist, Track $track)
    {
        $this->authorize('update', $playlist);

        (new PlaylistTracks($playlist))->remove($track);

        $playlist
            ->load(['user', 'coverTrack.release'])
            ->loadCount('tracks');

        return (new PlaylistResource($playlist))
            ->additional([
                'message' => __('Removed from :title.', ['title' => $playlist->title]),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
