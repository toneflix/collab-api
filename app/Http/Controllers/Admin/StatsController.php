<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminStatsResource;
use App\Services\Admin\AdminStats;
use Illuminate\Http\Request;

/**
 * The numbers on the admin overview.
 */
class StatsController extends Controller
{
    public function __invoke(Request $request, AdminStats $stats)
    {
        abort_unless(AdminStats::allows($request->user()), HttpStatus::FORBIDDEN->value, 'You do not have access to the admin overview.');

        $overview = $stats->for($request->user());

        return (new AdminStatsResource($overview))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
