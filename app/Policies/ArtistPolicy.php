<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ArtistPolicy
{
    /**
     * The account that manages the artist and content moderators can edit
     * its profile.
     */
    public function update(User $user, Artist $artist): Response
    {
        return $artist->isManagedBy($user) || $this->moderates($user)
            ? Response::allow()
            : Response::deny('Only the artist\'s account and admins can edit this profile.');
    }

    /**
     * Only the account that manages the artist can give it a voice for AI
     * music.
     */
    public function voice(User $user, Artist $artist): Response
    {
        return $artist->isManagedBy($user)
            ? Response::allow()
            : Response::deny('Only the artist\'s account can manage its voice.');
    }

    /**
     * People can ask to manage an artist no account manages yet.
     */
    public function claim(User $user, Artist $artist): Response
    {
        return $artist->isClaimable()
            ? Response::allow()
            : Response::deny('This artist is already managed by an account.');
    }

    /**
     * Only admins can mark an artist as verified.
     */
    public function verify(User $user, Artist $artist): bool
    {
        return $this->moderates($user);
    }

    protected function moderates(User $user): bool
    {
        return $user->checkPermissionTo('manage-content');
    }
}
