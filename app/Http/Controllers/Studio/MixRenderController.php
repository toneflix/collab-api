<?php

namespace App\Http\Controllers\Studio;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\MixRenderResource;
use App\Models\ProjectTrack;
use App\Services\Studio\MixPreview;
use Illuminate\Http\Request;

/**
 * The signed links the studio's render page uses, in headless Chrome on the
 * worker, to read a track and upload its mix.
 */
class MixRenderController extends Controller
{
    /**
     * The track as the render page mixes it.
     */
    public function show(ProjectTrack $track)
    {
        $track->load('stems');

        return (new MixRenderResource($track))
            ->additional([
                'plugins' => filter_var(Providers::config('studio_plugins', false), FILTER_VALIDATE_BOOL),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Keep the mix the render page made, unless a newer one was asked for.
     */
    public function store(Request $request, ProjectTrack $track, string $mix, MixPreview $preview)
    {
        $this->validate($request, [
            'audio' => ['required', 'file', 'mimes:flac,wav', 'max:' . (config('studio.mix_max_mb') * 1024)],
        ]);

        $preview->upload($track, $request->file('audio')->getRealPath(), $mix);

        return (new MixRenderResource($track))
            ->additional([
                'message' => 'Preview mix saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
