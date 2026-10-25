<?php

namespace App\Providers;

use App\Helpers\Providers;
use App\Notifications\Channels\PushChannel;
use App\Services\Ai\Replicate\ReplicateProvider;
use App\Services\Moderation\Ai\ModeratorModel;
use App\Services\Moderation\Ai\ReplicateModeratorModel;
use App\Services\Notifications\WebPush;
use App\Services\Payment\FlutterwaveGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PayoutProviders;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeConnectGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Minishlink\WebPush\WebPush as WebPushClient;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\ReportHandlerInterface;

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

        $this->app->bind(ModeratorModel::class, ReplicateModeratorModel::class);

        $this->app->singleton(PayoutProviders::class, fn($app) => new PayoutProviders([
            $app->make(PaystackGateway::class),
            $app->make(FlutterwaveGateway::class),
            $app->make(StripeConnectGateway::class),
        ]));

        $this->app->when(PushChannel::class)->needs(WebPushClient::class)->give(fn() => WebPush::client());
        $this->app->when(PushChannel::class)->needs(ReportHandlerInterface::class)->give(ReportHandler::class);
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

        Ai::extend('replicate', fn($app, array $config) => new ReplicateProvider($config, $app['events']));
    }
}
