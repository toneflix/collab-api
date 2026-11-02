<?php

namespace App\Http\Controllers\Studio;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\StemRenderResource;
use App\Models\ProjectTrackStem;
use App\Services\Studio\InstrumentAudio;
use Illuminate\Http\Request;

/**
 * The signed links the studio's render page uses, in headless Chrome on the
 * worker, to read an instrument stem and upload its audio.
 */
class StemRenderController extends Controller
{
    /**
     * The stem as the render page plays it.
     */
    public function show(ProjectTrackStem $stem)
    {
        $stem->load('track');

        return (new StemRenderResource($stem))
            ->additional([
                'plugins' => filter_var(Providers::config('studio_plugins', false), FILTER_VALIDATE_BOOL),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Keep the stem's audio, unless its notes or effects changed since it was asked for.
     */
    public function store(Request $request, ProjectTrackStem $stem, string $hash, InstrumentAudio $audio)
    {
        $this->validate($request, [
            'audio' => ['required', 'file', 'mimes:flac,wav', 'max:' . (config('studio.mix_max_mb') * 1024)],
        ]);

        $audio->keep($stem, $request->file('audio')->getRealPath(), $hash);
        $stem->load('track');

        return (new StemRenderResource($stem))
            ->additional([
                'message' => 'Stem audio saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
