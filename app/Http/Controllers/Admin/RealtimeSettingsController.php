<?php

namespace App\Http\Controllers\Admin;

use App\Broadcasting\Channels;
use App\Enums\HttpStatus;
use App\Events\RealtimeMessage;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Services\Realtime\Realtime;
use App\Services\Realtime\RealtimeSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets admins choose the realtime driver. Each driver's keys come from `.env`.
 */
class RealtimeSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change realtime settings.'
            );

            return $next($request);
        });
    }

    public function show()
    {
        return $this->settings();
    }

    public function update(Request $request)
    {
        $valid = $request->validate([
            'realtime_driver' => ['required', Rule::in(RealtimeSettings::DRIVERS)],
        ]);

        RealtimeSettings::update($valid);
        Realtime::configure();

        $missing = $this->missing();

        return $this->settings(
            $missing ? 'Saved, but ' . $valid['realtime_driver'] . ' still needs these in .env: ' . implode(', ', $missing) . '.' : 'Realtime settings saved.',
            HttpStatus::ACCEPTED
        );
    }

    /**
     * Send a test event to the admin's own channel.
     */
    public function test(Request $request)
    {
        if (! Realtime::enabled()) {
            return Providers::response()->error(['message' => 'Realtime is turned off.'], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        if ($missing = $this->missing()) {
            return Providers::response()->error([
                'message' => 'Set ' . implode(', ', $missing) . ' in .env first.',
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        try {
            broadcast(new RealtimeMessage([Channels::user($request->user())], 'realtime.test', [
                'message' => 'Realtime is working.',
                'at' => now()->toIso8601String(),
                'origin' => Realtime::origin(),
            ]));
        } catch (\Throwable $e) {
            return Providers::response()->error([
                'message' => 'The test event could not be sent: ' . $e->getMessage(),
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        return Providers::response()->success([
            'data' => ['channel' => 'private-user.' . $request->user()->id, 'event' => 'realtime.test'],
            'message' => 'Test event sent.',
        ]);
    }

    /**
     * Keys the chosen driver needs that `.env` leaves empty.
     *
     * @return array<int, string>
     */
    protected function missing(): array
    {
        return RealtimeSettings::missing(RealtimeSettings::driver());
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        return Providers::response()->success([
            'data' => [
                'driver' => RealtimeSettings::driver(),
                'drivers' => RealtimeSettings::DRIVERS,
                'keys' => RealtimeSettings::keys(),
                'missing' => $this->missing(),
                'client' => Realtime::clientConfig(),
            ],
            'message' => $message,
        ], $status);
    }
}
