<?php

namespace App\Helpers;

use App\Enums\PeriodDays;
use App\Models\Release;
use App\Models\Track;
use Coderflex\Laravisit\PendingVisit;

class Freq
{
    public static function visit(Track|Release $visitable, int $interval = 15): PendingVisit
    {
        $visitor = auth('sanctum')->check()
            ? ['user' => auth('sanctum')->user()]
            : ['ip' => request()->ip()];

        return $visitable
            ->visit()
            ->customInterval(now()->subMinutes($interval))
            ->withData($visitor);
    }

    public static function period(?string $period): int
    {
        if (!$period) {
            $period = 'year';
        }

        return collect(PeriodDays::cases())
            ->firstWhere(fn (PeriodDays $p) => $p->name === mb_strtoupper($period))
            ?->value ?: PeriodDays::YEARS_2->value;
    }
}
