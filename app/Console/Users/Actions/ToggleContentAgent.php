<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;

use function Laravel\Prompts\confirm;

class ToggleContentAgent extends UserAction
{
    public function label(User $user): string
    {
        return $user->content_agent ? 'Stop the content agent for this account' : 'Make this a content agent account';
    }

    public function run(User $user): ?string
    {
        if (! confirm(label: $this->label($user) . '?', default: false)) {
            return null;
        }

        $this->manager->setContentAgent(null, $user, ! $user->content_agent);

        return $user->content_agent ? 'The content agent builds for this account now.' : 'The content agent stopped for this account.';
    }
}
