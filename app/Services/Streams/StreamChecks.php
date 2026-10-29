<?php

namespace App\Services\Streams;

use App\Models\AdminAction;
use App\Models\StreamCheck;
use App\Models\StreamPeriod;
use App\Models\TrackPlay;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SaleNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds listeners who loop one creator's tracks and holds those paid streams until an admin reviews them.
 */
class StreamChecks
{
    /**
     * Open a check for every listener whose paid streams in the month went mostly to one creator.
     */
    public function scan(Carbon $month): int
    {
        $rules = StreamRules::current();
        [$from, $to] = $this->bounds($month);
        $opened = 0;

        $totals = TrackPlay::query()
            ->toBase()
            ->select('user_id')
            ->selectRaw('count(*) as total')
            ->where('paid', true)
            ->whereBetween('played_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('user_id');

        TrackPlay::query()
            ->toBase()
            ->joinSub($totals, 'totals', 'totals.user_id', '=', 'track_plays.user_id')
            ->select('track_plays.user_id', 'track_plays.owner_id')
            ->selectRaw('count(*) as plays')
            ->selectRaw('max(totals.total) as total')
            ->where('track_plays.paid', true)
            ->whereNotNull('track_plays.owner_id')
            ->whereBetween('track_plays.played_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('track_plays.user_id', 'track_plays.owner_id')
            ->havingRaw('count(*) >= ?', [$rules->checkPlays])
            ->havingRaw('count(*) * 100 >= ? * max(totals.total)', [$rules->checkShare])
            ->orderBy('track_plays.user_id')
            ->orderBy('track_plays.owner_id')
            ->chunk(500, function ($rows) use ($from, &$opened) {
                foreach ($rows as $row) {
                    $share = round((int) $row->plays * 100 / max(1, (int) $row->total), 2);

                    $opened += (int) $this->open((int) $row->user_id, (int) $row->owner_id, $from, (int) $row->plays, $share);
                }
            });

        return $opened;
    }

    /**
     * Whether paid streams from this listener to this owner in the month are held.
     */
    public function holds(int $listenerId, int $ownerId, Carbon $day): bool
    {
        return StreamCheck::query()
            ->holding()
            ->where('user_id', $listenerId)
            ->where('owner_id', $ownerId)
            ->where('month', $day->copy()->startOfMonth()->toDateString())
            ->exists();
    }

    /**
     * Release the held streams. When the month was already paid out, the owner gets them at that month's rate.
     */
    public function clear(StreamCheck $check, User $admin): StreamCheck
    {
        return DB::transaction(function () use ($check, $admin) {
            $check
                ->fill([
                    'status' => StreamCheck::CLEARED,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ])
                ->save();

            $this->hold($check, false);
            $this->topUp($check);
            $this->record($admin, $check, 'stream_check_cleared');

            return $check;
        });
    }

    /**
     * Keep the streams out of every payout.
     */
    public function confirm(StreamCheck $check, User $admin): StreamCheck
    {
        $check
            ->fill([
                'status' => StreamCheck::CONFIRMED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])
            ->save();

        $this->hold($check, true);
        $this->record($admin, $check, 'stream_check_confirmed');

        return $check;
    }

    protected function open(int $listenerId, int $ownerId, Carbon $month, int $plays, float $share): bool
    {
        $check = StreamCheck::query()->firstOrNew([
            'user_id' => $listenerId,
            'owner_id' => $ownerId,
            'month' => $month->toDateString(),
        ]);

        if ($check->exists && $check->status !== StreamCheck::PENDING) {
            return false;
        }

        $opened = ! $check->exists;

        $check
            ->fill([
                'plays' => $plays,
                'share' => $share,
                'status' => StreamCheck::PENDING,
            ])
            ->save();

        $this->hold($check, true);

        return $opened;
    }

    protected function hold(StreamCheck $check, bool $held): void
    {
        [$from, $to] = $this->bounds($check->month);

        TrackPlay::query()
            ->where('user_id', $check->user_id)
            ->where('owner_id', $check->owner_id)
            ->where('paid', true)
            ->whereBetween('played_on', [$from->toDateString(), $to->toDateString()])
            ->update(['held' => $held]);
    }

    /**
     * Pay a cleared check's streams when its month was settled while they were held.
     */
    protected function topUp(StreamCheck $check): void
    {
        $period = StreamPeriod::query()
            ->where('month', $check->month->toDateString())
            ->where('status', StreamPeriod::SETTLED)
            ->first();
        $owner = $check->owner;

        if (! $period || $period->rate <= 0 || ! $owner || $check->top_up !== null) {
            return;
        }

        [$from, $to] = $this->bounds($check->month);

        $rules = StreamRules::current();
        $totals = TrackPlay::query()
            ->toBase()
            ->join('tracks', 'tracks.id', '=', 'track_plays.track_id')
            ->where('track_plays.user_id', $check->user_id)
            ->where('track_plays.owner_id', $check->owner_id)
            ->where('track_plays.paid', true)
            ->whereBetween('track_plays.played_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('count(*) as plays')
            ->selectRaw($rules->weightSql() . ' as weighted')
            ->first();

        $amount = round((float) $totals->weighted * $period->rate, 2);

        $check
            ->forceFill(['top_up' => $amount])
            ->save();

        if ($amount <= 0) {
            return;
        }

        $earning = new Transaction([
            'reference' => 'str_' . Str::lower(Str::random(24)),
            'purpose' => StreamEarnings::PURPOSE,
            'method' => 'streams',
            'status' => 'complete',
            'amount' => 0,
            'due' => 0,
            'recipient_id' => $owner->id,
            'recipient_share' => $amount,
            'meta' => [
                'title' => $period->month->format('F Y'),
                'month' => $period->month->toDateString(),
                'plays' => (int) $totals->plays,
                'weighted' => round((float) $totals->weighted, 2),
                'rate' => $period->rate,
                'check_id' => $check->id,
            ],
            'paid_at' => now(),
        ]);
        $earning
            ->transactable()
            ->associate($period);
        $earning->save();

        $period->increment('paid_out', $amount);

        $owner->notify(new SaleNotification($earning));
    }

    protected function record(User $admin, StreamCheck $check, string $action): void
    {
        AdminAction::query()->create([
            'admin_id' => $admin->id,
            'user_id' => $check->user_id,
            'action' => $action,
            'details' => [
                'owner_id' => $check->owner_id,
                'month' => $check->month->toDateString(),
                'plays' => $check->plays,
                'top_up' => $check->top_up,
            ],
        ]);
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
