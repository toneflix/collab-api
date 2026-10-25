<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Models\Track;
use App\Services\Lyrics\Lyrics;

/**
 * The lyrics the player shows for a released track.
 */
class LyricsController extends Controller
{
    public function show(Lyrics $lyrics, Track $track)
    {
        return Providers::response()->success([
            // Tracks released before lyrics were copied on release pick them up here.
            'data' => ($lyrics->for($track) ?? $lyrics->inherit($track))?->present(),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }
}
