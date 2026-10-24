<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCollaboratorCollection;
use App\Http\Resources\ProjectCollaboratorResource;
use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Models\User;
use App\Services\Limits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectCollaboratorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project)
    {
        $query = $project->collaborators()->getQuery()
            ->when($request->has('accepted'), fn (Builder $q) => $q->whereAccepted(true));

        $collaborators = $query->paginate($request->input('limit', 45));

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
        $valid = $this->validate($request, [
            'user_id' => [
                'required',
                'string',
                'exists:users,id',
                Rule::unique('project_collaborators', 'user_id')->where('project_id', $project->id),
            ],
            'role' => ['nullable', 'string'],
            'custom' => ['nullable', 'required_if:role,custom', 'string'],
        ], [
            'user_id.unique' => 'This user is already a collaber or already invited.',
        ]);

        if (isset($valid['custom'])) {
            $valid['meta']['custom'] = $valid['custom'];
        }

        // The project owner's plan decides how many collaborators it can have.
        Limits::for($project->user)->ensureBelow(
            'collaborators',
            $project->collaborators()->count(),
            'user_id',
            'This project can have up to :limit collaborators.'
        );

        /** @var ProjectCollaborator $collaborator */
        $collaborator = $project->collaborators()->create($valid);

        /** @var User $user */
        $user = $request->user();

        $user->sendApprovableNotification(
            $collaborator->user,
            'Invitation to join project',
            __(':0 is inviting you to to join the project: :1', [$user->fullname, $project->title]),
            ['type' => 'project_invitation', 'action' => 'invitation'],
            $collaborator
        );

        return (new ProjectCollaboratorResource($collaborator))->additional([
            'message' => __('An invitation has been sent to :0 to join this project.', [$collaborator->user->fullname]),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, ProjectCollaborator $collaborator)
    {
        return (new ProjectCollaboratorCollection($collaborator))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectCollaborator $collaborator)
    {
        $valid = $this->validate($request, [
            'role' => ['nullable', 'string'],
            'custom' => ['nullable', 'required_if:role,custom', 'string'],
        ]);

        if (isset($valid['custom'])) {
            $valid['meta']['custom'] = $valid['custom'];
        }

        $collaborator->update($valid);

        return (new ProjectCollaboratorResource($collaborator))->additional([
            'message' => __('Collaber updated'),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, ProjectCollaborator $collaborator)
    {
        $collaborator->delete();

        return (new ProjectCollaboratorResource($collaborator))->additional([
            'message' => __(':0 has been removed from the project', [$collaborator->user->fullname]),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
