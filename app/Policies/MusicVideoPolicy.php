<?php

namespace App\Policies;

use App\Models\MusicVideo;
use App\Models\User;

/**
 * Music videos belong to the person who made them.
 */
class MusicVideoPolicy
{
    public function view(User $user, MusicVideo $video): bool
    {
        return (int) $video->user_id === (int) $user->id;
    }

    public function update(User $user, MusicVideo $video): bool
    {
        return $this->view($user, $video);
    }

    public function delete(User $user, MusicVideo $video): bool
    {
        return $this->view($user, $video);
    }
}
