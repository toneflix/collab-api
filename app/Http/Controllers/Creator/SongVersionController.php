<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Http\Resources\SongVersionCollection;
use App\Http\Resources\SongVersionResource;
use App\Models\Song;
use App\Models\SongVersion;
use App\Services\Composer\Songs;
use Illuminate\Http\Request;

/**
 * A song's saved versions: list them, name one, restore one.
 */
class SongVersionController extends Controller
{
    public function index(Request $request, Song $song)
    {
        $this->authorize('view', $song);

        $versions = $song
            ->versions()
            ->latest('id')
            ->paginate($request->perPage(20));

        return (new SongVersionCollection($versions))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Keeps the song as it is now under a name. Named versions are never pruned.
     */
    public function store(Request $request, Songs $songs, Song $song)
    {
        $this->authorize('update', $song);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $version = $songs->snapshot($song, $this->user, SongVersion::NAMED, $data['name']);

        return (new SongVersionResource($version))
            ->additional([
                'message' => 'Version saved.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function restore(Songs $songs, Song $song, SongVersion $version)
    {
        $this->authorize('update', $song);

        $song = $songs->restore($song, $version, $this->user);
        $song->load('beat');

        return (new SongResource($song))
            ->additional([
                'message' => 'Version restored.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
