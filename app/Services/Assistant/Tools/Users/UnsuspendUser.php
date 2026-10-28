<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;

class UnsuspendUser extends UserTool
{
    public function name(): string
    {
        return 'unsuspend_user';
    }

    public function description(): string
    {
        return 'Lift a suspension or ban.';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'reason' => ['nullable|string|max:255', 'Why, for the audit trail'],
        ];
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'suspend');

        $this->manager()->unsuspend($admin, $user, $input['reason'] ?? null);

        return $this->brief($user);
    }
}
