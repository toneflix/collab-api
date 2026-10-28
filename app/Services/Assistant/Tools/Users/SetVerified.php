<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;

class SetVerified extends UserTool
{
    public function name(): string
    {
        return 'set_verified';
    }

    public function description(): string
    {
        return 'Give or take away an account\'s verified badge, and confirm its email address when giving it if asked.';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'verified' => ['required|boolean', 'true to give the badge, false to take it away'],
            'email' => ['nullable|boolean', 'Also mark the email address as confirmed'],
        ];
    }

    public function confirms(array $input): bool
    {
        return ! filter_var($input['verified'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'verify');

        return "Take the verified badge from {$this->handle($user)}";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'verify');

        $this->manager()->verify($admin, $user, (bool) $input['verified'], (bool) ($input['email'] ?? false));

        return $this->brief($user);
    }

    protected function confirmNote(): string
    {
        return ' Taking the badge away needs the admin\'s confirmation.';
    }
}
