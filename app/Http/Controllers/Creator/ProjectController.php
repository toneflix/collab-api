<?php

namespace App\Http\Controllers\Creator;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Limits;
use App\Services\Reputation;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = str($request->filters)->remove(' ')->explode(',');

        if ($request->boolean('own')) {
            $query = $this->user->projects()->getQuery();
        } else {
            $query = Project::query();
            $query->collaber($this->user);
        }

        $query->when($filters->contains('latest'), fn($q) => $q->latest());

        $projects = $query->paginate($request->perPage(45));

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

        Limits::for($this->user)->ensureBelow(
            Feature::MAX_PROJECTS,
            $this->user->projects()->count(),
            'title',
            'Your account can have up to :limit projects.'
        );

        $project = $this->user->projects()->create([
            'title' => $request->input('title'),
            'privacy' => $request->input('privacy', 'private'),
            'description' => $request->input('description'),
        ]);

        (new Reputation(
            $this->user,
            $this->user->projects()->count() > 1 ? 'create_project' : 'first_project'
        ))->add();

        return (new ProjectResource($project))->additional([
            'message' => HttpStatus::message(HttpStatus::CREATED),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $query = Project::query();
        $query->collaber($this->user);

        $project = $query->where(fn($q) => $q->where('slug', $id)->when(is_numeric($id), fn($q) => $q->orWhere('projects.id', $id)))->firstOrFail();

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
        $query = Project::query();
        $query->collaber($this->user);
        $project = $query->where(fn($q) => $q->where('slug', $id)->when(is_numeric($id), fn($q) => $q->orWhere('projects.id', $id)))->firstOrFail();

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
            'message' => 'Project updated successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        /** @var Project $project */
        $project = $this->user->projects()
            ->where(fn($q) => $q->where('slug', $id)->when(is_numeric($id), fn($q) => $q->orWhere('projects.id', $id)))
            ->first();

        if (! $project) {
            abort(HttpStatus::FORBIDDEN->value, 'You are not autorized to perform this action');
        }

        if ($project->delete()) {
            (new Reputation($this->user, 'delete_project'))->add($project->socialInteractionData()['props']);
        }

        return (new ProjectCollection([]))->additional([
            'message' => 'Project deleted successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
