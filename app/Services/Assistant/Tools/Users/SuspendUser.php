<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use Illuminate\Support\Carbon;

class SuspendUser extends UserTool
{
    public function name(): string
    {
        return 'suspend_user';
    }

    public function description(): string
    {
        return 'Suspend an account for some days, or ban it when no days are given. It is signed out everywhere.';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'days' => ['nullable|integer|min:1|max:3650', 'Days to suspend for; leave out to ban'],
            'reason' => ['required|string|max:255', 'Why, shown to the account owner'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'suspend');
        $days = $input['days'] ?? null;

        return $days
            ? "Suspend {$this->handle($user)} for {$days} " . str('day')->plural($days) . ": {$input['reason']}"
            : "Ban {$this->handle($user)}: {$input['reason']}";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'suspend');
        $until = isset($input['days']) ? Carbon::now()->addDays((int) $input['days']) : null;

        $this->manager()->suspend($admin, $user, $until, $input['reason']);

        return $this->brief($user);
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
