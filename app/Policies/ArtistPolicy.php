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
