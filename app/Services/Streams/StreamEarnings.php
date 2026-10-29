<?php

namespace App\Services\Streams;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Models\StreamPeriod;
use App\Models\Subscription;
use App\Models\TrackPlay;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SaleNotification;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shares each month's stream pool among creators by their weighted paid streams.
 */
class StreamEarnings
{
    public const PURPOSE = 'streams';

    /**
     * Pay out a finished month. Safe to run again: owners already paid are skipped.
     */
    public function settle(Carbon $month): StreamPeriod
    {
        $month = $month
            ->copy()
            ->startOfMonth();

        abort_if(
            $month->greaterThanOrEqualTo(now()->startOfMonth()),
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            'Only past months can be settled.',
        );

        $period = StreamPeriod::query()->firstOrNew(['month' => $month->toDateString()]);

        if ($period->status === StreamPeriod::SETTLED) {
            return $period;
        }

        (new StreamChecks)->scan($month);

        $period
            ->fill($this->preview($month))
            ->fill(['status' => StreamPeriod::SETTLING])
            ->save();

        $this->payOwners($period);

        $paid = $period
            ->earnings()
            ->where('purpose', self::PURPOSE);

        $period
            ->fill([
                'status' => StreamPeriod::SETTLED,
                'earners' => (clone $paid)->count(),
                'paid_out' => (float) (clone $paid)->sum('recipient_share'),
                'settled_at' => now(),
            ])
            ->save();

        return $period;
    }

    /**
     * The month's pool and rate as they stand, without paying anyone.
     *
     * @return array{revenue: float, share: float, pool: float, plays: int, weighted: float, rate: float, weights: array<string, float>}
     */
    public function preview(Carbon $month): array
    {
        $rules = StreamRules::current();
        [$from, $to] = $this->bounds($month);

        $revenue = $this->revenue($from, $to);
        $totals = $this
            ->paidPlays($month)
            ->selectRaw('count(*) as plays')
            ->selectRaw($rules->weightSql() . ' as weighted')
            ->first();

        $pool = round($revenue * $rules->poolShare / 100, 2);
        $weighted = round((float) $totals->weighted, 2);

        return [
            'revenue' => round($revenue, 2),
            'share' => $rules->poolShare,
            'pool' => $pool,
            'plays' => (int) $totals->plays,
            'weighted' => $weighted,
            'rate' => $weighted > 0 ? round($pool / $weighted, 6) : 0,
            'weights' => $rules->weights,
        ];
    }

    /**
     * Premium revenue that falls in the month. A payment for several months
     * counts in each month its period covers, in proportion to the days.
     */
    public function revenue(Carbon $from, Carbon $to): float
    {
        $end = $to
            ->copy()
            ->addSecond();
        $revenue = 0.0;

        Subscription::query()
            ->whereNotNull('transaction_id')
            ->where('amount', '>', 0)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $from)
            ->select(['id', 'amount', 'starts_at', 'ends_at'])
            ->chunkById(1000, function ($periods) use ($from, $end, &$revenue) {
                foreach ($periods as $period) {
                    $length = $period->starts_at->diffInSeconds($period->ends_at);
                    $overlap = $period->starts_at
                        ->max($from)
                        ->diffInSeconds($period->ends_at->min($end));

                    $revenue += $length > 0 ? $period->amount * $overlap / $length : 0;
                }
            });

        return round($revenue, 2);
    }

    /**
     * A creator's paid streams this month and what they earned from streams so far.
     *
     * @return array{plays: int, held: int, weighted: float, estimate: float, earned: float, lastRate: float, currency: string}
     */
    public function summary(User $user): array
    {
        $rules = StreamRules::current();
        $mine = $this
            ->paidPlays(now())
            ->where('track_plays.owner_id', $user->id)
            ->selectRaw('count(*) as plays')
            ->selectRaw($rules->weightSql() . ' as weighted')
            ->first();
        [$from, $to] = $this->bounds(now());
        $held = TrackPlay::query()
            ->where('owner_id', $user->id)
            ->where('paid', true)
            ->where('held', true)
            ->whereBetween('played_on', [$from->toDateString(), $to->toDateString()])
            ->count();
        $last = StreamPeriod::query()
            ->where('status', StreamPeriod::SETTLED)
            ->latest('month')
            ->first();
        $earned = $user
            ->earnings()
            ->where('purpose', self::PURPOSE)
            ->sum('recipient_share');

        $weighted = round((float) $mine->weighted, 2);

        return [
            'plays' => (int) $mine->plays,
            'held' => $held,
            'weighted' => $weighted,
            'estimate' => round($weighted * (float) ($last?->rate ?? 0), 2),
            'earned' => round((float) $earned, 2),
            'lastRate' => (float) ($last?->rate ?? 0),
            'currency' => (string) Providers::config('app_currency', 'NGN'),
        ];
    }

    /**
     * One earning per owner, in batches.
     */
    protected function payOwners(StreamPeriod $period): void
    {
        if ($period->rate <= 0) {
            return;
        }

        $rules = StreamRules::current();

        $this
            ->paidPlays($period->month)
            ->whereNotNull('track_plays.owner_id')
            ->select('track_plays.owner_id')
            ->selectRaw('count(*) as plays')
            ->selectRaw($rules->weightSql() . ' as weighted')
            ->groupBy('track_plays.owner_id')
            ->orderBy('track_plays.owner_id')
            ->chunk(500, function ($rows) use ($period) {
                foreach ($rows as $row) {
                    $this->pay($period, (int) $row->owner_id, (int) $row->plays, (float) $row->weighted);
                }
            });
    }

    protected function pay(StreamPeriod $period, int $ownerId, int $plays, float $weighted): void
    {
        $amount = round($weighted * $period->rate, 2);

        $owner = User::query()->find($ownerId);

        if ($amount <= 0 || ! $owner) {
            return;
        }

        $exists = Transaction::query()
            ->where('purpose', self::PURPOSE)
            ->where('recipient_id', $ownerId)
            ->whereMorphedTo('transactable', $period)
            ->exists();

        if ($exists) {
            return;
        }

        $earning = new Transaction([
            'reference' => 'str_' . Str::lower(Str::random(24)),
            'purpose' => self::PURPOSE,
            'method' => 'streams',
            'status' => 'complete',
            'amount' => 0,
            'due' => 0,
            'recipient_id' => $ownerId,
            'recipient_share' => $amount,
            'meta' => [
                'title' => $period->month->format('F Y'),
                'month' => $period->month->toDateString(),
                'plays' => $plays,
                'weighted' => round($weighted, 2),
                'rate' => $period->rate,
            ],
            'paid_at' => now(),
        ]);
        $earning
            ->transactable()
            ->associate($period);
        $earning->save();

        $owner->notify(new SaleNotification($earning));
    }

    /**
     * Paid plays in a month that no stream check holds, joined to their tracks for the weights.
     */
    protected function paidPlays(Carbon $month): QueryBuilder
    {
        [$from, $to] = $this->bounds($month);

        return TrackPlay::query()
            ->toBase()
            ->join('tracks', 'tracks.id', '=', 'track_plays.track_id')
            ->where('track_plays.paid', true)
            ->where('track_plays.held', false)
            ->whereBetween('track_plays.played_on', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function bounds(Carbon $month): array
    {
        $from = $month
            ->copy()
            ->startOfMonth();
        $to = $month
            ->copy()
            ->endOfMonth();

        return [$from, $to];
    }
}
