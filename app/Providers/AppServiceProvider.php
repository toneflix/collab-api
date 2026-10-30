<?php

namespace App\Providers;

use App\Enums\AiEngine;
use App\Helpers\Providers;
use App\Notifications\Channels\PushChannel;
use App\Services\Ai\AiJobStops;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateProvider;
use App\Services\Assistant\Ai\AssistantModel;
use App\Services\Assistant\Ai\CloudflareAssistantModel;
use App\Services\Assistant\Ai\ReplicateAssistantModel;
use App\Services\Composer\Ai\CloudflareLyricModel;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Composer\Ai\ReplicateLyricModel;
use App\Services\Moderation\Ai\CloudflareModeratorModel;
use App\Services\Moderation\Ai\ModeratorModel;
use App\Services\Moderation\Ai\ReplicateModeratorModel;
use App\Services\Notifications\Fcm;
use App\Services\Notifications\WebPush;
use App\Services\Payment\FlutterwaveGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGateways;
use App\Services\Payment\PayoutProviders;
use App\Services\Payment\PaystackGateway;
use App\Services\Payment\StripeConnectGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
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

        $this->app->bind(PaymentGateways::class, fn($app) => new PaymentGateways([
            $app->make(PaymentGateway::class),
            $app->make(FlutterwaveGateway::class),
        ]));

        $this->app->bind(ModeratorModel::class, fn($app) => $app->make(
            AiSettings::engine('ai_moderator_engine') === AiEngine::CLOUDFLARE ? CloudflareModeratorModel::class : ReplicateModeratorModel::class
        ));
        $this->app->bind(AssistantModel::class, fn($app) => $app->make(
            AiSettings::engine('ai_assistant_engine') === AiEngine::CLOUDFLARE ? CloudflareAssistantModel::class : ReplicateAssistantModel::class
        ));
        $this->app->bind(LyricModel::class, fn($app) => $app->make(
            AiSettings::engine('ai_composer_engine') === AiEngine::CLOUDFLARE ? CloudflareLyricModel::class : ReplicateLyricModel::class
        ));

        $this->app->scoped(AiJobStops::class);

        $this->app->singleton(PayoutProviders::class, fn($app) => new PayoutProviders([
            $app->make(PaystackGateway::class),
            $app->make(FlutterwaveGateway::class),
            $app->make(StripeConnectGateway::class),
        ]));

        $this->app
            ->when(PushChannel::class)
            ->needs(WebPushClient::class)
            ->give(fn() => WebPush::client());
        $this->app
            ->when(PushChannel::class)
            ->needs(ReportHandlerInterface::class)
            ->give(ReportHandler::class);
        $this->app->bind(Messaging::class, fn() => Fcm::messaging());
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
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Composer's editor looks up words and autosaves as the writer types.
        RateLimiter::for('composer', function (Request $request) {
            return Limit::perMinute(300)
                ->by('composer:' . ($request->user()?->id ?: $request->ip()));
        });

        Providers::rateLimitCodeRequests();

        Ai::extend('replicate', fn($app, array $config) => new ReplicateProvider($config, $app['events']));
    }
}
