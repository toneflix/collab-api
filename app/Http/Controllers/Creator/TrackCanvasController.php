<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrackCanvasResource;
use App\Models\Track;
use App\Models\TrackCanvas;
use App\Services\Ai\Video\Canvases;
use App\Services\Ai\Video\CanvasStorage;
use Illuminate\Http\Request;

/**
 * Canvases: the looping clips released tracks show in the player. Owners make
 * one with AI or upload their own; listeners get the ready one.
 */
class TrackCanvasController extends Controller
{
    /**
     * The track's ready canvas for the player, or an empty object.
     */
    public function track(Canvases $canvases, Track $track)
    {
        $canvas = $canvases->forTrack($track);

        return (new TrackCanvasResource($canvas))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * The owner's newest canvas of the track, whatever its state.
     */
    public function show(Request $request, Canvases $canvases, Track $track)
    {
        abort_unless($track->release?->isOwnedBy($request->user()), HttpStatus::FORBIDDEN->value, 'You can only change the canvas of your own tracks.');

        $canvas = $canvases->latest($track);

        return (new TrackCanvasResource($canvas))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Start making a canvas with AI from the track's artwork.
     */
    public function store(Request $request, Canvases $canvases, Track $track)
    {
        $valid = $request->validate([
            'brief' => ['nullable', 'string', 'max:500'],
        ]);

        $canvas = $canvases->generate($request->user(), $track, $valid['brief'] ?? null);

        return (new TrackCanvasResource($canvas))
            ->additional([
                'message' => 'Making your canvas.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Use the owner's own clip as the canvas.
     */
    public function upload(Request $request, Canvases $canvases, Track $track)
    {
        $request->validate([
            'clip' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:51200'],
        ]);

        $canvas = $canvases->upload($request->user(), $track, $request->file('clip'));

        return (new TrackCanvasResource($canvas))
            ->additional([
                'message' => 'Canvas saved.',
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }

    /**
     * Take the canvas off the track.
     */
    public function destroy(Request $request, Canvases $canvases, Track $track)
    {
        $canvases->remove($request->user(), $track);

        $canvas = null;

        return (new TrackCanvasResource($canvas))
            ->additional([
                'message' => 'Canvas removed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * A canvas clip, from its signed link.
     */
    public function clip(CanvasStorage $storage, TrackCanvas $canvas)
    {
        return $storage->stream($canvas->file, 'video/mp4');
    }

    public function poster(CanvasStorage $storage, TrackCanvas $canvas)
    {
        return $storage->stream($canvas->poster, 'image/jpeg');
    }
}
