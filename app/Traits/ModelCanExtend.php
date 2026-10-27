<?php

namespace App\Traits;

use App\Models\Scopes\HideDeactivatedAccounts;
use App\Models\Scopes\HideTakenDown;
use Illuminate\Support\Stringable;
use Valorin\Random\Random;

trait ModelCanExtend
{
    protected static function userNameQuery() {}

    /**
     * Generate a Username for the user.
     *
     *
     * @return string
     */
    protected static function generateUsername(
        string|Stringable $string,
        string $field = 'username',
        string $separator = '_',
        ?string $current = null,
        ?callable $scope = null,
    ) {
        $username = str($current ?: $string)
            ->slug($separator);

        if (static::query()->withoutGlobalScopes([HideTakenDown::class, HideDeactivatedAccounts::class])->where($field, $username)->when($scope, $scope)->exists()) {
            $username = $username
                ->append($separator)
                ->append(Random::number(1111, 9999));
        }

        return $username->toString();
    }
}
