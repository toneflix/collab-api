<?php

namespace App\Console\Commands;

use App\Console\Users\UserBrowser;
use App\Models\User;
use App\Services\Admin\UserDirectory;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    protected $signature = '
        app:users
            {--s|search= : Match the id, username, email or name}
            {--status= : Only active, suspended, premium, verified or staff users}
            {--r|role= : Only users with this role}
            {--p|permission= : Only users with this permission, directly or through a role}
            {--sort=latest : Sort by latest, oldest or name}
            {--page=1 : The page to start on}
            {--per-page=20 : Users per page (max 100)}
    ';

    protected $description = 'Browse and manage users, or print a page of them with --no-interaction';

    /** @var list<string> */
    protected $aliases = ['list:users'];

    public function handle(UserDirectory $directory, UserBrowser $browser): int
    {
        $filters = [
            'search' => $this->option('search'),
            'status' => $this->option('status'),
            'role' => $this->option('role'),
            'permission' => $this->option('permission'),
            'sort' => $this->option('sort'),
        ];

        if ($filters['status'] && ! in_array($filters['status'], UserDirectory::STATUSES)) {
            $this->error('The status must be one of: ' . implode(', ', UserDirectory::STATUSES) . '.');

            return self::INVALID;
        }

        if (! in_array($filters['sort'], UserDirectory::SORTS)) {
            $this->error('The sort must be one of: ' . implode(', ', UserDirectory::SORTS) . '.');

            return self::INVALID;
        }

        $perPage = max(1, min(100, (int) $this->option('per-page')));
        $page = max(1, (int) $this->option('page'));

        if ($this->input->isInteractive()) {
            $browser->browse($filters, $perPage, $page);

            return self::SUCCESS;
        }

        $users = $directory
            ->query($filters)
            ->with('roles')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($users->isEmpty()) {
            $this->info($page > 1 ? 'There are no users on this page.' : 'No users match.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Username', 'Name', 'Email', 'Roles', 'Verified', 'Status', 'Joined'],
            $users
                ->getCollection()
                ->map(fn(User $user) => [
                    $user->id,
                    $user->username,
                    $user->fullname,
                    $user->email,
                    $user->roles->pluck('name')->implode(', ') ?: '-',
                    $user->verified ? 'yes' : 'no',
                    $this->status($user),
                    $user->created_at?->toDateString(),
                ]),
        );

        $this->line("Page {$users->currentPage()} of {$users->lastPage()}, {$users->total()} users.");

        return self::SUCCESS;
    }

    protected function status(User $user): string
    {
        if (! $user->isSuspended()) {
            return 'active';
        }

        return $user->suspended_until ? 'suspended until ' . $user->suspended_until->toDateString() : 'banned';
    }
}
