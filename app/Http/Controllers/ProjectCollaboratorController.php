<?php

namespace App\Http\Controllers;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\ProjectCollaboratorCollection;
use App\Http\Resources\ProjectCollaboratorResource;
use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Models\User;
use App\Services\Limits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProjectCollaboratorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project)
    {
        $query = $project->collaborators()->getQuery()
            ->when($request->boolean('accepted'), fn(Builder $q) => $q->whereAccepted(true))
            ->when($request->boolean('pending'), fn(Builder $q) => $q->whereAccepted(false));

        $collaborators = $query->paginate($request->perPage(45));

        return (new ProjectCollaboratorCollection($collaborators))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project)
    {
        $this->middleware('auth:sanctum');

        $valid = $this->validate($request, [
            'role' => 'required',
            'custom' => ['nullable', 'required_if:role,custom', 'string'],
        ]);

        /** @var User $sender */
        $sender = $request->user('sanctum');

        if ($project->isCollaber($sender)) {
            return Providers::response()->error([
                'message' => 'You are already a collaber on this project.',
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        $role = $request->role === 'custom' ? $request->custom : $request->role;

        $valid['user_id'] = $sender->id;

        if (isset($valid['custom'])) {
            $valid['meta']['custom'] = $valid['custom'];
        }

        // The project owner's plan decides how many collaborators it can have.
        Limits::for($project->user)->ensureBelow(
            Feature::MAX_COLLABORATORS,
            // Only people already on the project count; requests don't take a spot until accepted.
            $project->collaborators()->accepted()->count(),
            'role',
            'This project can have up to :limit collaborators.'
        );

        /** @var ProjectCollaborator $collaborator */
        $collaborator = $project->collaborators()->create($valid);

        $sender->sendApprovableNotification(
            actionable: $collaborator,
            recipient: $project->user,
            message: __(':0 is requesting to join :1 as a :2 collaber', [$sender->fullname, $project->title, $role]),
            title: 'Collab Request',
            data: [
                'type' => 'project_request',
                'action' => 'request',
                'icon' => 'fas fa-info',
            ],
        );

        return (new ProjectCollaboratorResource($collaborator))->additional([
            'message' => __('Your request to join :0 as a :1 has been sent.', [
                $project->title,
                $role,
            ]),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Project $project, ProjectCollaborator $collaborator)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectCollaborator $collaborator)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, string $cid)
    {
        $collaborator = $project->collaborators()->getQuery()
            ->when($cid === 'self', fn(Builder $q) => $q->whereUserId($request->user('sanctum')?->id))
            ->when($cid !== 'self', fn(Builder $q) => $q->whereId($cid))
            ->firstOrFail();

        $collaborator->request()->delete();
        $collaborator->delete();

        return (new ProjectCollaboratorResource($collaborator))->additional([
            'message' => __(! $collaborator->accepted ? 'Your request has been cancelled.' : 'You have now left the project.'),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
