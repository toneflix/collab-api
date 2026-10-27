<?php

namespace App\Models\Scopes;

use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * Hides deactivated accounts, and everything they own, from app requests.
 * Jobs, commands, admin pages, sign-in and payment webhooks still see them.
 */
class HideDeactivatedAccounts implements Scope
{
    /**
     * Route names that still see deactivated accounts.
     *
     * @var array<int, string>
     */
    public const SEES_DEACTIVATED = [
        'admin.*',
        'login',
        'password.*',
        'payments.webhook',
        'payouts.webhook.*',
    ];

    public function apply(Builder $builder, Model $model): void
    {
        if (! static::active()) {
            return;
        }

        if ($model instanceof User) {
            $builder->whereNull($model->qualifyColumn('deactivated_at'));

            return;
        }

        if ($model instanceof Track) {
            $builder->whereNotExists(fn(QueryBuilder $query) => $query
                ->selectRaw('1')
                ->from('releases')
                ->join('users', 'users.id', '=', 'releases.user_id')
                ->whereColumn('releases.id', 'tracks.release_id')
                ->whereNotNull('users.deactivated_at'));

            return;
        }

        $builder->whereNotExists(fn(QueryBuilder $query) => $query
            ->selectRaw('1')
            ->from('users')
            ->whereColumn('users.id', $model->qualifyColumn('user_id'))
            ->whereNotNull('users.deactivated_at'));
    }

    /**
     * Whether the current request hides deactivated accounts.
     */
    public static function active(): bool
    {
        $route = request()->route();

        if (! $route) {
            return false;
        }

        return ! Str::is(self::SEES_DEACTIVATED, (string) $route->getName());
    }
}
