<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StreamCheckCollection;
use App\Http\Resources\StreamCheckResource;
use App\Models\StreamCheck;
use App\Models\TrackPlay;
use App\Services\Streams\StreamChecks;
use App\Services\Streams\StreamRules;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Listeners whose paid streams went mostly to one creator, held until an admin reviews them.
 */
class StreamCheckController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-payouts'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to manage payouts.'
            );

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(StreamCheck::STATUSES)],
        ]);

        $status = $request->input('status', StreamCheck::PENDING);

        $checks = StreamCheck::query()
            ->with(['user', 'owner', 'reviewer'])
            ->where('status', $status)
            ->when(
                $status === StreamCheck::PENDING,
                fn(Builder $q) => $q->oldest('id'),
                fn(Builder $q) => $q->latest('reviewed_at'),
            )
            ->paginate($request->perPage(20));

        $checks
            ->getCollection()
            ->each(fn(StreamCheck $check) => $check->setRelation('tracks', $this->topTracks($check)));

        $pending = StreamCheck::query()
            ->where('status', StreamCheck::PENDING)
            ->count();

        return (new StreamCheckCollection($checks))
            ->additional([
                'summary' => ['pendingCount' => $pending],
                'rules' => StreamRules::current()->toArray(),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Clear the check to pay its streams, or confirm it to keep them out of every payout.
     */
    public function update(Request $request, StreamCheck $check, StreamChecks $checks)
    {
        $request->validate([
            'decision' => ['required', Rule::in(['clear', 'confirm'])],
        ]);

        abort_unless(
            $check->status === StreamCheck::PENDING,
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            'This check was already reviewed.',
        );

        $request->input('decision') === 'clear'
            ? $checks->clear($check, $request->user())
            : $checks->confirm($check, $request->user());

        $check->load(['user', 'owner', 'reviewer']);

        return (new StreamCheckResource($check))
            ->additional([
                'message' => $check->status === StreamCheck::CLEARED
                    ? 'Streams released to the creator.'
                    : 'Streams kept out of the payout.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * The owner's tracks the listener played most that month.
     *
     * @return array<int, array{title: string, plays: int}>
     */
    protected function topTracks(StreamCheck $check): array
    {
        $from = $check->month
            ->copy()
            ->startOfMonth();
        $to = $check->month
            ->copy()
            ->endOfMonth();

        return TrackPlay::query()
            ->toBase()
            ->join('tracks', 'tracks.id', '=', 'track_plays.track_id')
            ->where('track_plays.user_id', $check->user_id)
            ->where('track_plays.owner_id', $check->owner_id)
            ->where('track_plays.paid', true)
            ->whereBetween('track_plays.played_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('tracks.id', 'tracks.title')
            ->select('tracks.title')
            ->selectRaw('count(*) as plays')
            ->orderByDesc('plays')
            ->limit(5)
            ->get()
            ->map(fn($row) => ['title' => (string) $row->title, 'plays' => (int) $row->plays])
            ->all();
    }
}
