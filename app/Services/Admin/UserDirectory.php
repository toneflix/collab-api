<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds users for admins by search text, status, role or permission.
 */
class UserDirectory
{
    public const STATUSES = ['active', 'suspended', 'premium', 'verified', 'staff'];

    public const SORTS = ['latest', 'oldest', 'name'];

    /**
     * @param  array{search?: ?string, status?: ?string, role?: ?string, permission?: ?string, sort?: ?string}  $filters
     * @return Builder<User>
     */
    public function query(array $filters = []): Builder
    {
        return User::query()
            ->withoutGlobalScopes()
            ->when($filters['search'] ?? null, fn(Builder $q, string $search) => $q->where(fn(Builder $q) => $q
                ->whereLike('username', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")
                ->orWhereLike('firstname', "%{$search}%")
                ->orWhereLike('lastname', "%{$search}%")
                ->when(is_numeric($search), fn($q) => $q->orWhere('id', $search))))
            ->when($filters['status'] ?? null, fn(Builder $q, string $status) => match ($status) {
                'active' => $q->suspended(false),
                'suspended' => $q->suspended(),
                'premium' => $q->whereHas('subscriptions', fn($q) => $q->active()),
                'verified' => $q->where('verified', true),
                'staff' => $q->whereHas('roles', fn($q) => $q->where('name', '!=', 'user')),
            })
            ->when($filters['role'] ?? null, fn(Builder $q, string $role) => $q->whereHas('roles', fn($q) => $q->where('name', $role)))
            ->when($filters['permission'] ?? null, fn(Builder $q, string $permission) => $q->where(fn(Builder $q) => $q
                ->whereHas('permissions', fn($q) => $q->where('name', $permission))
                ->orWhereHas('roles.permissions', fn($q) => $q->where('name', $permission))))
            ->when($filters['sort'] ?? 'latest', fn(Builder $q, string $sort) => match ($sort) {
                'oldest' => $q->oldest('id'),
                'name' => $q->orderBy('firstname')->orderBy('lastname')->orderBy('id'),
                default => $q->latest('id'),
            });
    }

    /**
     * Find one user by id, username or email.
     */
    public function find(string $key): ?User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where(fn(Builder $q) => $q
                ->where('username', $key)
                ->orWhere('email', $key)
                ->when(ctype_digit($key), fn($q) => $q->orWhere('id', (int) $key)))
            ->first();
    }
}
