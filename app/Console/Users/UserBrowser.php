<?php

namespace App\Console\Users;

use App\Models\User;
use App\Services\Admin\UserDirectory;
use Illuminate\Pagination\LengthAwarePaginator;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Pages through users with the arrow keys and opens the one picked in a UserMenu.
 */
class UserBrowser
{
    protected const PREVIOUS = 'previous';

    protected const NEXT = 'next';

    protected const SEARCH = 'search';

    protected const QUIT = 'quit';

    public function __construct(protected UserDirectory $directory, protected UserMenu $menu) {}

    /**
     * @param  array{search?: ?string, status?: ?string, role?: ?string, permission?: ?string, sort?: ?string}  $filters
     */
    public function browse(array $filters, int $perPage, int $page = 1): void
    {
        while (true) {
            $users = $this->page($filters, $perPage, $page);

            if ($users->isEmpty() && $page > 1) {
                $page = max(1, $users->lastPage());

                continue;
            }

            $choice = select(
                label: $this->heading($users, $filters),
                options: $this->options($users, $filters),
                scroll: min(20, $users->count() + 4),
                hint: 'Use the arrow keys and Enter.',
            );

            if ($choice === self::QUIT) {
                return;
            }

            if ($choice === self::SEARCH) {
                $filters['search'] = $this->search($filters);
                $page = 1;
            } elseif (in_array($choice, [self::PREVIOUS, self::NEXT])) {
                $page += $choice === self::NEXT ? 1 : -1;
            } else {
                $this->open(ltrim($choice, '#'));
            }
        }
    }

    /**
     * @param  array<string, ?string>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    protected function page(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->directory
            ->query($filters)
            ->with('roles')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $users
     * @param  array<string, ?string>  $filters
     */
    protected function heading(LengthAwarePaginator $users, array $filters): string
    {
        $search = ($filters['search'] ?? null) ? " matching \"{$filters['search']}\"" : '';

        if ($users->isEmpty()) {
            return "No users{$search}.";
        }

        return "Users{$search}, page {$users->currentPage()} of {$users->lastPage()} ({$users->total()} in all)";
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $users
     * @param  array<string, ?string>  $filters
     * @return array<string, string>
     */
    protected function options(LengthAwarePaginator $users, array $filters): array
    {
        $options = $users
            ->getCollection()
            ->mapWithKeys(fn(User $user) => ["#{$user->id}" => UserMenu::line($user)])
            ->all();

        if ($users->currentPage() > 1) {
            $options[self::PREVIOUS] = '← Previous page';
        }

        if ($users->hasMorePages()) {
            $options[self::NEXT] = 'Next page →';
        }

        $options[self::SEARCH] = ($filters['search'] ?? null) ? 'Change the search' : 'Search';
        $options[self::QUIT] = 'Quit';

        return $options;
    }

    /**
     * @param  array<string, ?string>  $filters
     */
    protected function search(array $filters): ?string
    {
        $search = text(
            label: 'Search by id, username, email or name',
            default: (string) ($filters['search'] ?? ''),
            hint: 'Leave it empty to list everyone.',
        );

        return trim($search) ?: null;
    }

    protected function open(string $id): void
    {
        $user = $this->directory->find($id);

        if (! $user) {
            info('That account is gone.');

            return;
        }

        $this->menu->open($user);
    }
}
