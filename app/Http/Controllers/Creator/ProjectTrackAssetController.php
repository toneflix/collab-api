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
use App\Services\Storage\UploadRules;
use Illuminate\Http\Request;

class ProjectTrackAssetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project, ProjectTrack $track)
    {
        $this->authorize('open', $track);

        $query = $track
            ->assets()
            ->getQuery();

        $assets = $query->get();

        return (new FileCollection($assets))
            ->additional([
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
        $this->authorize('update', $track);

        $allowedMimes = UploadRules::mimes('allowed_assets_exts');

        $valid = $this->validate($request, [
            'file' => ['required', 'file', $allowedMimes],
            'meta' => ['nullable', 'array'],
            'meta.name' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $file = $track
            ->assets()
            ->create($valid);

        return (new FileResource($file))
            ->additional([
                'message' => 'Track asset uploaded successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, ProjectTrack $track, File $asset)
    {
        $this->authorize('open', $track);

        return (new FileResource($asset))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectTrack $track, File $asset)
    {
        $this->authorize('update', $track);

        $allowedMimes = UploadRules::mimes('allowed_assets_exts');

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

        return (new FileResource($asset))
            ->additional([
                'message' => 'Track asset updated successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, ProjectTrack $track, File $asset)
    {
        $this->authorize('update', $track);

        $asset->delete();

        return (new FileCollection([]))
            ->additional([
                'message' => 'Track asset deleted successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
