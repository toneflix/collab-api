<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\TrackCollection;
use App\Models\Track;
use App\Services\Playlists\UpNext;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    /**
     * Tracks to keep playback going, like `similar_to` when given and never
     * the ones in `exclude` unless nothing else is left.
     */
    public function index(Request $request)
    {
        if (is_string($request->input('exclude'))) {
            $request->merge(['exclude' => array_values(array_filter(explode(',', $request->input('exclude'))))]);
        }

        $request->validate([
            'similar_to' => ['nullable', 'integer'],
            'exclude' => ['nullable', 'array', 'max:' . UpNext::MAX_EXCLUDED],
            'exclude.*' => ['integer'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $upNext = new UpNext(
            user: $request->user('sanctum'),
            seed: $request->filled('similar_to') ? Track::find($request->integer('similar_to')) : null,
            exclude: $request->input('exclude', []),
            randomize: $request->boolean('randomize'),
        );

        return (new TrackCollection($upNext->paginate($request->integer('limit', 15))))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
