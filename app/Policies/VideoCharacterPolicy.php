<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoCharacter;

/**
 * Saved characters belong to the person who saved them.
 */
class VideoCharacterPolicy
{
    public function view(User $user, VideoCharacter $character): bool
    {
        return (int) $character->user_id === (int) $user->id;
    }

    public function update(User $user, VideoCharacter $character): bool
    {
        return $this->view($user, $character);
    }

    public function delete(User $user, VideoCharacter $character): bool
    {
        return $this->view($user, $character);
    }
}
