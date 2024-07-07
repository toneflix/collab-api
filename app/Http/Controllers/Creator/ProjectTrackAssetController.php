<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\FileCollection;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use Illuminate\Http\Request;

class ProjectTrackAssetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project, ProjectTrack $track)
    {
        $query = $track->assets()->getQuery();

        $assets = $query->get();

        return (new FileCollection($assets))->additional([
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
        $allowedMimes = str(collect(Providers::config('allowed_assets_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'file' => ['required', 'file', $allowedMimes],
            'meta' => ['nullable', 'array'],
            'meta.name' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $file = $track->assets()->create($valid);

        return (new FileResource($file))->additional([
            'message' => 'Track asset uploaded successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, ProjectTrack $track, ProjectTrackStem $stem, File $asset)
    {
        return (new FileResource($asset))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem, File $asset)
    {
        $allowedMimes = str(collect(Providers::config('allowed_assets_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'file' => ['nullable', 'file', $allowedMimes],
            'meta' => ['required', 'array'],
            'meta.name' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        if (! collect(Providers::config('allow_file_updates', false))) {
            unset($valid['file']);
        }

        $asset->update($valid);

        return (new FileResource($asset))->additional([
            'message' => 'Track asset updated successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem, File $asset)
    {
        $asset->delete();

        return (new FileCollection([]))->additional([
            'message' => 'Track asset deleted successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }
}
