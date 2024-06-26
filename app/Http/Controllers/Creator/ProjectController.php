<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->type == 'collab') {
            $query = $this->user->collabProjects()->getQuery();
        } else {
            $query = $this->user->projects()->getQuery();
        }

        $projects = $query->paginate($request->input('limit', 45));

        return (new ProjectCollection($projects))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'image' => ['nullable', 'image', 'mimes:png,jpg'],
            'title' => ['required', 'string'],
            'privacy' => ['nullable', 'string', 'in:private,public,public_preview'],
            'description' => ['nullable', 'string'],
        ]);

        $project = $this->user->projects()->create([
            'title' => $request->input('title'),
            'privacy' => $request->input('privacy', 'private'),
            'description' => $request->input('description'),
        ]);

        return (new ProjectResource($project))->additional([
            'message' => HttpStatus::message(HttpStatus::CREATED),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $project = $this->user->projects()->whereSlug($id)->orWhere('id', $id)->firstOrFail();

        return (new ProjectResource($project))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $project = $this->user->projects()->whereSlug($id)->orWhere('id', $id)->firstOrFail();

        $this->validate($request, [
            'image' => ['nullable', 'image', 'mimes:png,jpg'],
            'title' => ['required', 'string'],
            'privacy' => ['nullable', 'string', 'in:private,public,public_preview'],
            'description' => ['nullable', 'string'],
        ]);

        $project->update([
            'title' => $request->input('title'),
            'privacy' => $request->input('privacy', 'private'),
            'description' => $request->input('description'),
        ]);

        return (new ProjectResource($project))->additional([
            'message' => "Project updated successfully.",
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        /** @var \App\Models\Project $project */
        $project = $this->user->collabProjects()
            ->whereId($id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        $project->delete();

        return (new ProjectCollection([]))->additional([
            'message' => "Project deleted successfully.",
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }
}
