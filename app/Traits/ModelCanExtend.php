<?php

namespace App\Traits;

use Valorin\Random\Random;

trait ModelCanExtend
{
    /**
     * Generate a Username for the user.
     *
     *
     * @return string
     */
    protected static function generateUsername(
        string|\Illuminate\Support\Stringable $string,
        string $field = 'username',
        string $separator = '_',
        ?string $current = null,
        ?\Illuminate\Database\Eloquent\Model $model = null
    ) {
        $username = str($current ?: $string)->slug($separator);

        if (static::where($field, $username)->exists()) {
            $username = $username->append($separator)->append(Random::number(1111, 9999));
        }

        return $username->toString();
    }
}
