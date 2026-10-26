<?php

namespace App\Policies;

use App\Models\Song;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only the writer edits a song. A published song can be read by anyone, and
 * forked or made into a project by others when the writer allows it.
 */
class SongPolicy
{
    public function view(User $user, Song $song): Response
    {
        return $this->owner($user, $song);
    }

    public function update(User $user, Song $song): Response
    {
        return $this->owner($user, $song);
    }

    public function delete(User $user, Song $song): Response
    {
        return $this->owner($user, $song);
    }

    /**
     * Reading the song's public page.
     */
    public function read(?User $user, Song $song): Response
    {
        return $song->isPublished() || $song->user_id === $user?->id
            ? Response::allow()
            : Response::denyAsNotFound('Composition not found.');
    }

    public function fork(User $user, Song $song): Response
    {
        if ($song->user_id === $user->id) {
            return Response::allow();
        }

        $this->read($user, $song)->authorize();

        return $song->allow_forks
            ? Response::allow()
            : Response::deny("This composition's writer doesn't allow forks.");
    }

    /**
     * Putting the song's lyrics on a project track.
     */
    public function project(User $user, Song $song): Response
    {
        if ($song->user_id === $user->id) {
            return Response::allow();
        }

        $this->read($user, $song)->authorize();

        return $song->allow_projects
            ? Response::allow()
            : Response::deny("This composition's writer doesn't allow projects with it.");
    }

    protected function owner(User $user, Song $song): Response
    {
        return $song->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound('Composition not found.');
    }
}
