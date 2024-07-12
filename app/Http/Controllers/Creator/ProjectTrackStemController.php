<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTrackStemCollection;
use App\Http\Resources\ProjectTrackStemResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Services\Reputation;
use Illuminate\Http\Request;

class ProjectTrackStemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project, ProjectTrack $track)
    {
        $query = $track->stems()->getQuery();

        $stems = $query->get();

        return (new ProjectTrackStemCollection($stems))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project, ProjectTrack $track)
    {
        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'src' => ['nullable', 'file', $allowedAudio],
            'meta' => ['nullable', 'array'],
            'title' => ['nullable', 'string'],
            'stem_id' => ['nullable', 'string', 'exists:project_track_stems,id'],
        ]);

        $valid['user_id'] = $request->user()->id;

        $stem = $track->stems()->create($valid);

        // Add reputation for the owner
        (new Reputation($this->user, 'create_project_track_stem'))->add();

        return (new ProjectTrackStemResource($stem))->additional([
            'message' => 'Track stem created successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        return (new ProjectTrackStemResource($stem))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'src' => ['nullable', 'file', $allowedAudio],
            'meta' => ['required', 'array'],
            'title' => ['nullable', 'string'],
            'stem_id' => ['nullable', 'string', 'exists:project_track_stems,id'],
        ]);

        $valid['user_id'] = $request->user()->id;

        $stem->update($valid);

        return (new ProjectTrackStemResource($stem))->additional([
            'message' => 'Track stem updated successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $stem->delete();

        return (new ProjectTrackStemCollection([]))->additional([
            'message' => 'Track stem deleted successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }
}
