<?php

namespace App\Console\Users;

use App\Models\User;
use App\Services\Admin\UserManager;

use function Laravel\Prompts\text;

/**
 * One thing the users console can do to an account, through UserManager.
 */
abstract class UserAction
{
    public function __construct(protected UserManager $manager) {}

    /**
     * The menu entry for this account.
     */
    abstract public function label(User $user): string;

    /**
     * Run the action and say what changed, or return null when it was cancelled.
     */
    abstract public function run(User $user): ?string;

    public function available(User $user): bool
    {
        return true;
    }

    /**
     * Ask why, for the audit trail.
     */
    protected function reason(bool $required = false, string $hint = ''): ?string
    {
        $reason = text(
            label: $required ? 'Reason' : 'Reason (optional)',
            required: $required,
            validate: fn(string $value) => mb_strlen($value) > 255 ? 'Keep it under 255 characters.' : null,
            hint: $hint,
        );

        return trim($reason) ?: null;
    }

    /**
     * The handle used in messages, like "@jane".
     */
    protected function handle(User $user): string
    {
        return "@{$user->username}";
    }
}
