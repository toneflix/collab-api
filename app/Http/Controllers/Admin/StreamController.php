<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StreamPeriodCollection;
use App\Http\Resources\StreamPeriodResource;
use App\Models\StreamPeriod;
use App\Services\Streams\StreamEarnings;
use App\Services\Streams\StreamRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Settled months of stream earnings, and the month in progress.
 */
class StreamController extends Controller
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

    public function index(Request $request, StreamEarnings $earnings)
    {
        $periods = StreamPeriod::query()
            ->latest('month')
            ->paginate($request->perPage(12, 60));

        $current = [
            'month' => now()->format('Y-m'),
            ...$earnings->preview(now()),
        ];

        return (new StreamPeriodCollection($periods))
            ->additional([
                'current' => $current,
                'rules' => StreamRules::current()->toArray(),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Pay out a past month now instead of waiting for the schedule.
     */
    public function settle(Request $request, StreamEarnings $earnings)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m', 'before:' . now()->format('Y-m')],
        ]);

        $month = Carbon::createFromFormat('Y-m', $request->string('month')->toString())->startOfMonth();

        $period = $earnings->settle($month);

        return (new StreamPeriodResource($period))
            ->additional([
                'message' => 'Stream earnings paid out.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
