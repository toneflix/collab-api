<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTrackResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Services\Studio\MixPreview;
use Illuminate\Http\Request;

class ProjectTrackMixController extends Controller
{
    /**
     * Keep the mix the studio made in the browser as the track's preview.
     */
    public function store(Request $request, Project $project, ProjectTrack $track, MixPreview $preview)
    {
        $this->authorize('update', $track);

        $this->validate($request, [
            'audio' => ['required', 'file', 'mimes:flac,wav', 'max:' . (config('studio.mix_max_mb') * 1024)],
        ]);

        $preview->upload($track, $request->file('audio')->getRealPath());

        return (new ProjectTrackResource($track))
            ->additional([
                'message' => 'Preview mix saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
