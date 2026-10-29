<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;

use function Laravel\Prompts\confirm;

class ConfirmEmail extends UserAction
{
    public function label(User $user): string
    {
        return 'Confirm the email address';
    }

    public function available(User $user): bool
    {
        return $user->email_verified_at === null;
    }

    public function run(User $user): ?string
    {
        if (! confirm(label: "Mark {$user->email} as confirmed?", default: false)) {
            return null;
        }

        $this->manager->verify(null, $user, (bool) $user->verified, true);

        return 'The email address is confirmed.';
    }
}
