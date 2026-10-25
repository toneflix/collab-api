<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\LyricResource;
use App\Models\Track;
use App\Services\Lyrics\Lyrics;

/**
 * The lyrics the player shows for a released track.
 */
class LyricsController extends Controller
{
    public function show(Lyrics $lyrics, Track $track)
    {
        // Tracks released before lyrics were copied on release pick them up here.
        $lyric = $lyrics->for($track) ?? $lyrics->inherit($track);

        if (! $lyric) {
            return Providers::response()->success(['data' => null, 'message' => HttpStatus::message(HttpStatus::OK)]);
        }

        return (new LyricResource($lyric))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
