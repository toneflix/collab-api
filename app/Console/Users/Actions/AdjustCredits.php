<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;

use function Laravel\Prompts\text;

class AdjustCredits extends UserAction
{
    public function label(User $user): string
    {
        return 'Grant or take back AI credits';
    }

    public function run(User $user): ?string
    {
        $credits = (int) text(
            label: 'Credits',
            required: true,
            validate: fn(string $value) => preg_match('/^-?[1-9]\d{0,6}$/', $value) ? null : 'Enter a whole number other than zero.',
            hint: 'A negative number takes credits back.',
        );
        $reason = $this->reason(true);

        $this->manager->adjustCredits(null, $user, $credits, $reason);

        return $credits > 0 ? "Granted {$credits} credits." : 'Took back ' . abs($credits) . ' credits.';
    }
}
