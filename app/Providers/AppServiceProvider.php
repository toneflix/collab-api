<?php

namespace App\Providers;

use App\Helpers\Providers;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\PayoutGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            PaymentGateway::class,
            PaystackGateway::class
        );

        $this->app->bind(
            PayoutGateway::class,
            PaystackGateway::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Providers::buildResponseMacros();

        // Force https
        if (config('app.env') === 'production' || config('app.force_https')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Providers::rateLimitCodeRequests();
    }
}
