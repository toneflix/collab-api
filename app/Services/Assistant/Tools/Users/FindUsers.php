<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use App\Services\Admin\UserDirectory;
use Illuminate\Validation\Rule;

class FindUsers extends UserTool
{
    public const PER_PAGE = 10;

    public function name(): string
    {
        return 'find_users';
    }

    public function description(): string
    {
        return 'Search accounts by name, username, email or id, and filter them by status or role. Newest first, 10 a page.';
    }

    public function input(): array
    {
        return [
            'search' => ['nullable|string|max:100', 'Text to look for'],
            'status' => [['nullable', Rule::in(UserDirectory::STATUSES)], 'One of ' . implode(', ', UserDirectory::STATUSES)],
            'role' => ['nullable|string|max:50', 'A role name, like admin'],
            'sort' => [['nullable', Rule::in(UserDirectory::SORTS)], 'One of ' . implode(', ', UserDirectory::SORTS)],
            'page' => ['nullable|integer|min:1', 'Page of results, from 1'],
        ];
    }

    public function run(User $admin, array $input): array
    {
        $users = app(UserDirectory::class)
            ->query($input)
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) ($input['page'] ?? 1));

        return $this->paged($users, fn(User $user) => $this->brief($user));
    }
}
