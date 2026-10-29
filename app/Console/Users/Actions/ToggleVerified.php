<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;

use function Laravel\Prompts\confirm;

class ToggleVerified extends UserAction
{
    public function label(User $user): string
    {
        return $user->verified ? 'Remove the verified badge' : 'Give the verified badge';
    }

    public function run(User $user): ?string
    {
        if (! confirm(label: $this->label($user) . '?', default: false)) {
            return null;
        }

        $this->manager->verify(null, $user, ! $user->verified);

        return $user->verified ? 'The badge was given.' : 'The badge was removed.';
    }
}
