<?php

namespace App\Providers;

use App\Listeners\ApprovableNotificationListener;
use App\Listeners\Follows;
use App\Listeners\SocialInteractionRecieved;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Overtrue\LaravelFollow\Events\Followed;
use Overtrue\LaravelFollow\Events\Unfollowed;
use ToneflixCode\ApprovableNotifications\Events\ApprovableNotificationCreated;
use ToneflixCode\ApprovableNotifications\Events\ApprovableNotificationUpdated;
use Toneflix\SocialInteractions\Events\SocialInteractionDone;

class EventServiceProvider extends ServiceProvider
{
    public $listen = [
        ApprovableNotificationCreated::class => [ApprovableNotificationListener::class],
        ApprovableNotificationUpdated::class => [ApprovableNotificationListener::class],
        SocialInteractionDone::class => [SocialInteractionRecieved::class],
        Unfollowed::class => [Follows::class],
        Followed::class => [Follows::class],
    ];

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
