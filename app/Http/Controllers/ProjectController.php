<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Freq;
use App\Http\Resources\ProjectCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Project::query();

        $filters = str($request->filters)->remove(' ')->explode(',');
        $days = Freq::period($request->period);

        $query
            ->where(function (Builder $query) {
                $query->where('privacy', 'public');
                $query->orWhere('user_id', auth('sanctum')->id());
                $query->orWhereHas(
                    'collaborators',
                    fn (Builder $q) => $q->whereUserId(auth('sanctum')->id())->whereAccepted(true)
                );
            })
            ->when($request->has('genre'), fn (Builder $q) => $q->genre($request->genre))
            ->when($filters->contains('top'), fn ($q) => $q->top(true))
            ->when($filters->contains('latest'), fn ($q) => $q->orderByLastDays($days, 'desc'))
            ->when($filters->contains('active'), fn ($q) => $q->released(false)->latest())
            ->when($request->has('creator_id'), fn ($q) => $q->collaber($request->creator_id)->latest());

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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        return (new ProjectResource($project))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        //
    }
}
