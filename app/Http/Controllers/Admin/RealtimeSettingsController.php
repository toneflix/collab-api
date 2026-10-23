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
 * Lets admins choose the realtime driver and enter its keys.
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
            'reverb_port' => ['nullable', 'integer', 'between:1,65535'],
            'reverb_scheme' => ['nullable', Rule::in(['http', 'https'])],
            'ably_key' => ['nullable', 'string', 'regex:/^[^:]+:.+$/'],
            'firebase_credentials' => ['nullable', 'json'],
            'firebase_database_url' => ['nullable', 'url'],
            ...collect(RealtimeSettings::FIELDS)
                ->except(['realtime_driver', 'reverb_port', 'reverb_scheme', 'ably_key', 'firebase_credentials', 'firebase_database_url'])
                ->map(fn () => ['nullable', 'string', 'max:255'])
                ->all(),
        ], [
            'ably_key.regex' => 'Use the full API key, like appId.keyId:secret.',
        ]);

        RealtimeSettings::update($valid);
        Realtime::configure();

        $missing = $this->missing();

        return $this->settings(
            $missing ? 'Saved, but '.$valid['realtime_driver'].' still needs: '.implode(', ', $missing).'.' : 'Realtime settings saved.',
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
                'message' => 'Fill in '.implode(', ', $missing).' first.',
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
                'message' => 'The test event could not be sent: '.$e->getMessage(),
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        return Providers::response()->success([
            'data' => ['channel' => 'private-user.'.$request->user()->id, 'event' => 'realtime.test'],
            'message' => 'Test event sent.',
        ]);
    }

    /**
     * Settings the chosen driver needs that are still empty.
     *
     * @return array<int, string>
     */
    protected function missing(): array
    {
        $required = [
            'reverb' => ['reverb_app_id', 'reverb_key', 'reverb_secret', 'reverb_host'],
            'pusher' => ['pusher_app_id', 'pusher_key', 'pusher_secret', 'pusher_cluster'],
            'ably' => ['ably_key'],
            'firebase' => ['firebase_credentials', 'firebase_database_url', 'firebase_api_key', 'firebase_project_id'],
        ][RealtimeSettings::driver()] ?? [];

        $all = RealtimeSettings::all();

        return collect($required)
            ->filter(fn ($key) => blank($all[$key] ?? null))
            ->map(fn ($key) => RealtimeSettings::FIELDS[$key][0])
            ->values()
            ->all();
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        return Providers::response()->success([
            'data' => [
                'driver' => RealtimeSettings::driver(),
                'drivers' => RealtimeSettings::DRIVERS,
                'fields' => RealtimeSettings::forAdmin(),
                'missing' => $this->missing(),
                'client' => Realtime::clientConfig(),
            ],
            'message' => $message,
        ], $status);
    }
}
