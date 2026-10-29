<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\TrackCreditsResource;
use App\Models\Track;
use App\Services\Releases\TrackCredits;

/**
 * The credits listed for a released track.
 */
class TrackCreditsController extends Controller
{
    public function show(Track $track)
    {
        $track->loadMissing(['release', 'genre', 'projectTrack.project']);

        $credits = new TrackCredits($track);

        return (new TrackCreditsResource($credits))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
