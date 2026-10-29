<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class SuspendAccount extends UserAction
{
    public function label(User $user): string
    {
        return 'Suspend or ban';
    }

    public function available(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function run(User $user): ?string
    {
        $days = text(
            label: 'Suspend for how many days?',
            validate: fn(string $value) => $value === '' || (ctype_digit($value) && (int) $value >= 1 && (int) $value <= 3650)
                ? null
                : 'Enter a number of days from 1 to 3650, or nothing to ban.',
            hint: 'Leave it empty to ban the account.',
        );
        $reason = $this->reason(true, 'The owner sees this.');
        $until = $days === '' ? null : Carbon::now()->addDays((int) $days);

        $sure = confirm(
            label: $until ? "Suspend {$this->handle($user)} until {$until->toDayDateTimeString()}?" : "Ban {$this->handle($user)}?",
            default: false,
        );

        if (! $sure) {
            return null;
        }

        $this->manager->suspend(null, $user, $until, $reason);

        return $until ? "{$this->handle($user)} is suspended and signed out." : "{$this->handle($user)} is banned and signed out.";
    }
}
