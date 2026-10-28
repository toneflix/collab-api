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
     * Any track of a project the user can see: tracks follow their project's privacy.
     */
    public function view(?User $user, ProjectTrack $track): Response
    {
        if ($user && $track->project?->canEdit($user)) {
            return Response::allow();
        }

        $open = $track->project
            && (new ProjectPolicy)->view($user, $track->project)->allowed();

        return $open
            ? Response::allow()
            : Response::denyAsNotFound('This track is private.');
    }

    /**
     * Whether the user can open the track in the studio, with its stems,
     * assets and live edits: its people, or anyone who can see the track of
     * a public project. Public preview projects only play their mixes.
     */
    public function open(?User $user, ProjectTrack $track): Response
    {
        $view = $this->view($user, $track);

        if ($view->denied()) {
            return $view;
        }

        return $track->project->opensStudioTo($user)
            ? Response::allow()
            : Response::deny('This project only shares previews of its tracks.');
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
