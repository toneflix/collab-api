<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;

use function Laravel\Prompts\confirm;

class SignOut extends UserAction
{
    public function label(User $user): string
    {
        return 'Sign out everywhere';
    }

    public function run(User $user): ?string
    {
        if (! confirm(label: "Sign {$this->handle($user)} out on every device?", default: false)) {
            return null;
        }

        $this->manager->signOut(null, $user, $this->reason());

        return "{$this->handle($user)} is signed out everywhere.";
    }
}
