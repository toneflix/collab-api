<?php

namespace App\Policies;

use App\Models\ProjectTrack;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who can work on a project's tracks and their stems, assets, history and AI
 * jobs: the project's owner and its accepted collaborators.
 */
class ProjectTrackPolicy
{
    /**
     * Public tracks of a project the user can see, or any track they work on.
     */
    public function view(?User $user, ProjectTrack $track): Response
    {
        if ($user && $track->project?->canEdit($user)) {
            return Response::allow();
        }

        $open = $track->privacy === 'public'
            && $track->project
            && (new ProjectPolicy)->view($user, $track->project)->allowed();

        return $open
            ? Response::allow()
            : Response::denyAsNotFound('This track is private.');
    }

    public function update(User $user, ProjectTrack $track): Response
    {
        return $this->editable($user, $track);
    }

    public function delete(User $user, ProjectTrack $track): Response
    {
        return $this->editable($user, $track);
    }

    public function viewHistory(User $user, ProjectTrack $track): Response
    {
        return $this->editable($user, $track);
    }

    protected function editable(User $user, ProjectTrack $track): Response
    {
        return $track->project?->canEdit($user)
            ? Response::allow()
            : Response::deny('Only the owner and collaborators can work on this track.');
    }
}
