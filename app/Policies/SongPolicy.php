<?php

namespace App\Policies;

use App\Models\Song;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Songs are private to the writer who made them.
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

    protected function owner(User $user, Song $song): Response
    {
        return $song->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound('Song not found.');
    }
}
