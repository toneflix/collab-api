<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ActionResource;
use App\Http\Resources\PlaylistCollection;
use App\Http\Resources\PlaylistResource;
use App\Models\Playlist;
use App\Services\Playlists\PlaylistTracks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listeners' own playlists: their list, each playlist's page, and changes
 * to the playlist itself.
 */
class PlaylistsController extends Controller
{
    /**
     * The signed in user's playlists, newest change first. With `track_id`
     * each says whether it holds that track.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'track_id' => ['nullable', 'integer'],
        ]);

        $trackId = $data['track_id'] ?? null;
        $search = $data['search'] ?? null;

        $playlists = Playlist::query()
            ->ownedBy($request->user())
            ->with('coverTrack.release')
            ->withCount('tracks')
            ->when($search, fn(Builder $q) => $q->where('title', 'ilike', '%' . addcslashes($search, '%_\\') . '%'))
            ->when(
                $trackId,
                fn(Builder $q) => $q->withExists(['tracks as has_track' => fn(Builder $t) => $t->whereKey($trackId)]),
            )
            ->latest('updated_at')
            ->latest('id')
            ->paginate($request->perPage(20, 50));

        return (new PlaylistCollection($playlists))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);

        PlaylistTracks::ensureRoomFor($request->user());

        $playlist = $request
            ->user()
            ->playlists()
            ->create($data);

        $playlist
            ->load('user')
            ->loadCount('tracks');

        return (new PlaylistResource($playlist))
            ->additional([
                'message' => __('Playlist created.'),
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function show(Playlist $playlist)
    {
        Gate::forUser($this->user)->authorize('view', $playlist);

        $playlist
            ->load(['user', 'coverTrack.release'])
            ->loadCount('tracks');

        return (new PlaylistResource($playlist))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function update(Request $request, Playlist $playlist)
    {
        $this->authorize('update', $playlist);

        $data = $this->rules($request, true);

        $playlist->update($data);
        $playlist
            ->load(['user', 'coverTrack.release'])
            ->loadCount('tracks');

        return (new PlaylistResource($playlist))
            ->additional([
                'message' => __('Playlist updated.'),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(Playlist $playlist)
    {
        $this->authorize('delete', $playlist);

        $playlist->delete();

        return (new ActionResource(['deleted' => true]))
            ->additional([
                'message' => __('Playlist deleted.'),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'privacy' => ['sometimes', Rule::in([Playlist::PUBLIC, Playlist::PRIVATE])],
        ]);
    }
}
