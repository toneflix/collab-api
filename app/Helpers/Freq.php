<?php

namespace App\Helpers;

use App\Enums\PeriodDays;

class Freq
{
    public static function period(?string $period): int
    {
        if (! $period) {
            $period = 'year';
        }

        return collect(PeriodDays::cases())
            ->firstWhere(fn(PeriodDays $p) => $p->name === mb_strtoupper($period))
            ?->value ?: PeriodDays::YEARS_2->value;
    }
}
