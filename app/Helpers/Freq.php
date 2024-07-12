<?php

namespace App\Helpers;

use App\Enums\PeriodDays;
use App\Models\Release;
use App\Models\Track;
use App\Services\Reputation;
use Coderflex\Laravisit\PendingVisit;

class Freq
{
    public static function visit(Track|Release $visitable, int $interval = 15): PendingVisit
    {
        $visitor = auth('sanctum')->check()
            ? ['user' => auth('sanctum')->user()]
            : ['ip' => request(null)->ip()];

        if ($visitable instanceof Track) {
            $owner = $visitable->release->user;
            $event = 'track_views';
        } else {
            $owner = $visitable->user;
            $event = 'release_views';
        }

        // Add reputation for the owner
        (new Reputation($owner, $event))->add();

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
