<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use App\Services\Auth\TwoFactor;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class ResetTwoFactor extends UserAction
{
    public function label(User $user): string
    {
        return 'Reset two-factor sign-in';
    }

    public function available(User $user): bool
    {
        return app(TwoFactor::class)->enabled($user);
    }

    public function run(User $user): ?string
    {
        warning('Only do this once you know the request comes from the owner. They can sign in with just their password until they set it up again.');

        $reason = $this->reason(true);

        if (! confirm(label: "Turn two-factor sign-in off for {$this->handle($user)}?", default: false)) {
            return null;
        }

        $this->manager->resetTwoFactor(null, $user, $reason);

        return 'Two-factor sign-in is off for this account.';
    }
}
