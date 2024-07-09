<?php

namespace App\Providers;

use App\Listeners\ApprovableNotificationListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ProvidersEventServiceProvider;
use ToneflixCode\ApprovableNotifications\Events\ApprovableNotificationCreated;
use ToneflixCode\ApprovableNotifications\Events\ApprovableNotificationUpdated;

class EventServiceProvider extends ProvidersEventServiceProvider
{
    public $listen = [
        ApprovableNotificationCreated::class => [
            ApprovableNotificationListener::class
        ],
        ApprovableNotificationUpdated::class => [
            ApprovableNotificationListener::class
        ],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
    }
}
