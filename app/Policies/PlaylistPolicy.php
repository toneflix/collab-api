<?php

namespace App\Policies;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only the owner changes a playlist. Public playlists can be opened by
 * anyone with the link; private ones look missing to everyone else.
 */
class PlaylistPolicy
{
    public function view(?User $user, Playlist $playlist): Response
    {
        return $playlist->isPublic() || $playlist->isOwnedBy($user)
            ? Response::allow()
            : Response::denyAsNotFound('Playlist not found.');
    }

    public function update(User $user, Playlist $playlist): Response
    {
        return $playlist->isOwnedBy($user)
            ? Response::allow()
            : Response::denyAsNotFound('Playlist not found.');
    }

    public function delete(User $user, Playlist $playlist): Response
    {
        return $this->update($user, $playlist);
    }
}
