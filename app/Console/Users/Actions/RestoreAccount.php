<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;

class RestoreAccount extends UserAction
{
    public function label(User $user): string
    {
        return $user->suspended_until ? 'Lift the suspension' : 'Lift the ban';
    }

    public function available(User $user): bool
    {
        return $user->isSuspended();
    }

    public function run(User $user): ?string
    {
        $this->manager->unsuspend(null, $user, $this->reason());

        return "{$this->handle($user)} can sign in again.";
    }
}
