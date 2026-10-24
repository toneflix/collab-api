<?php

namespace App\Providers;

use App\Broadcasting\Channels;
use App\Broadcasting\FirebaseBroadcaster;
use App\Services\Realtime\Realtime;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('realtime-live', function (Request $request) {
            return Limit::perMinute(600)->by($request->user()?->id ?: $request->ip());
        });

        $this->callAfterResolving(BroadcastManager::class, function (BroadcastManager $manager) {
            $manager->extend('firebase', fn ($app, array $config) => new FirebaseBroadcaster($config));
        });

        // Apply the admin's driver choice: right away for console commands,
        // and the first time something is broadcast for web requests.
        if ($this->app->runningInConsole()) {
            $this->configure();
        } else {
            $this->callAfterResolving(BroadcastManager::class, fn () => $this->configure());
        }
    }

    protected function configure(): void
    {
        try {
            Realtime::configure();
        } catch (\Throwable $e) {
            // No database yet (fresh install, package discovery): keep the .env driver.
        }
    }

    /**
     * Register the channels on the broadcaster in use.
     */
    public static function channels(): void
    {
        Channels::register(app(BroadcastManager::class)->connection());
    }
}
