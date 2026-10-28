<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use App\Services\Ai\Credits;

class AdjustCredits extends UserTool
{
    public function name(): string
    {
        return 'adjust_credits';
    }

    public function description(): string
    {
        return 'Grant AI credits to an account, or take bought and granted ones back with a negative number.';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'credits' => ['required|integer|between:-1000000,1000000|not_in:0', 'Credits to grant, negative to take back'],
            'reason' => ['required|string|max:255', 'Why, for the audit trail'],
        ];
    }

    public function confirms(array $input): bool
    {
        return (int) ($input['credits'] ?? 0) < 0;
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'credits');
        $credits = abs((int) $input['credits']);

        return "Take {$credits} AI credits from {$this->handle($user)}: {$input['reason']}";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'credits');

        $this->manager()->adjustCredits($admin, $user, (int) $input['credits'], $input['reason']);

        return [
            ...$this->brief($user),
            'credits' => Credits::for($user)->balance(),
        ];
    }

    protected function confirmNote(): string
    {
        return ' Taking credits back needs the admin\'s confirmation.';
    }
}
