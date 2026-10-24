<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Public and preview projects are open to everyone; private ones to their
     * owner and accepted collaborators.
     */
    public function view(?User $user, Project $project): Response
    {
        $open = in_array($project->privacy, ['public', 'public_preview'], true);

        return $open || ($user && $project->canEdit($user))
            ? Response::allow()
            : Response::denyAsNotFound('This project is private.');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Project $project): Response
    {
        return $project->canEdit($user)
            ? Response::allow()
            : Response::deny('Only the owner and collaborators can work on this project.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }
}
