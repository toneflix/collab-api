<?php

namespace App\Services\Streams;

use App\Enums\TrackOrigin;
use App\Models\StreamPeriod;
use App\Models\TrackPlay;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Plays, paid streams and stream earnings for a set of tracks, month by month.
 * Earnings use each settled month's rate and weights; the month in progress is
 * estimated at the last settled rate.
 */
class StreamStats
{
    public const MONTHS = 12;

    /**
     * @param  Closure(QueryBuilder): mixed  $scope  Narrows track_plays to the tracks or owner asked about.
     * @return array{totals: array<string, int|float>, months: array<int, array<string, mixed>>}
     */
    public function report(Closure $scope): array
    {
        $months = $this->months($scope);

        $all = TrackPlay::query()
            ->toBase()
            ->tap($scope)
            ->selectRaw('count(*) as plays')
            ->selectRaw('count(distinct track_plays.user_id) as listeners')
            ->first();

        return [
            'totals' => [
                'plays' => (int) $all->plays,
                'listeners' => (int) $all->listeners,
                'paid' => array_sum(array_column($months, 'paid')),
                'held' => array_sum(array_column($months, 'held')),
                'earned' => round(array_sum(array_column($months, 'earned')), 2),
            ],
            'months' => $months,
        ];
    }

    /**
     * The last twelve months, oldest first.
     *
     * @param  Closure(QueryBuilder): mixed  $scope
     * @return array<int, array{month: string, plays: int, paid: int, held: int, earned: float, settled: bool}>
     */
    public function months(Closure $scope): array
    {
        $from = now()
            ->startOfMonth()
            ->subMonthsNoOverflow(self::MONTHS - 1);
        $periods = StreamPeriod::query()
            ->where('month', '>=', $from->toDateString())
            ->where('status', StreamPeriod::SETTLED)
            ->get()
            ->keyBy(fn(StreamPeriod $period) => $period->month->format('Y-m'));
        $lastRate = (float) StreamPeriod::query()
            ->where('status', StreamPeriod::SETTLED)
            ->latest('month')
            ->value('rate');
        $rules = StreamRules::current();

        $months = [];

        for ($month = $from->copy(); $month->lessThanOrEqualTo(now()); $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = [
                'month' => $month->format('Y-m'),
                'plays' => 0,
                'paid' => 0,
                'held' => 0,
                'earned' => 0.0,
                'settled' => $periods->has($month->format('Y-m')),
            ];
        }

        $rows = TrackPlay::query()
            ->toBase()
            ->join('tracks', 'tracks.id', '=', 'track_plays.track_id')
            ->tap($scope)
            ->where('track_plays.played_on', '>=', $from->toDateString())
            ->groupBy('track_plays.played_on', 'tracks.origin')
            ->select('track_plays.played_on', 'tracks.origin')
            ->selectRaw('count(*) as plays')
            ->selectRaw('sum(case when track_plays.paid and not track_plays.held then 1 else 0 end) as paid')
            ->selectRaw('sum(case when track_plays.paid and track_plays.held then 1 else 0 end) as held')
            ->get();

        foreach ($rows as $row) {
            $key = Carbon::parse($row->played_on)->format('Y-m');

            if (! isset($months[$key])) {
                continue;
            }

            $period = $periods->get($key);
            $origin = TrackOrigin::tryFrom((string) $row->origin) ?? TrackOrigin::AGENTIC;
            $weight = (float) ($period?->weights[$origin->value] ?? $rules->weight($origin));
            $rate = $period ? $period->rate : $lastRate;

            $months[$key]['plays'] += (int) $row->plays;
            $months[$key]['paid'] += (int) $row->paid;
            $months[$key]['held'] += (int) $row->held;
            $months[$key]['earned'] += (int) $row->paid * $weight * $rate;
        }

        return array_values(array_map(
            fn(array $month) => [...$month, 'earned' => round($month['earned'], 2)],
            $months,
        ));
    }

    /**
     * The rate a month pays per weighted stream, and whether it is settled.
     *
     * @return array{rate: float, settled: bool, weights: array<string, float>}
     */
    public function rate(Carbon $month): array
    {
        $period = StreamPeriod::query()
            ->where('month', $month->copy()->startOfMonth()->toDateString())
            ->where('status', StreamPeriod::SETTLED)
            ->first();
        $lastRate = (float) StreamPeriod::query()
            ->where('status', StreamPeriod::SETTLED)
            ->latest('month')
            ->value('rate');

        return [
            'rate' => $period ? $period->rate : $lastRate,
            'settled' => (bool) $period,
            'weights' => $period?->weights ?? StreamRules::current()->weights,
        ];
    }
}
