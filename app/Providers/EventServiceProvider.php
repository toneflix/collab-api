<?php

namespace App\Providers;

use App\Events\AwaitingReview;
use App\Listeners\ApprovableNotificationListener;
use App\Listeners\Follows;
use App\Listeners\NotificationSentToApp;
use App\Listeners\RememberFileMeta;
use App\Listeners\SendToAiModerator;
use App\Listeners\SocialInteractionRecieved;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Notifications\Events\NotificationSent;
use Overtrue\LaravelFollow\Events\Followed;
use Overtrue\LaravelFollow\Events\Unfollowed;
use Toneflix\ApprovableNotifications\Events\ApprovableNotificationCreated;
use Toneflix\ApprovableNotifications\Events\ApprovableNotificationUpdated;
use Toneflix\LaravelFileable\Events\FileSaved;
use Toneflix\SocialInteractions\Events\SocialInteractionDone;

class EventServiceProvider extends ServiceProvider
{
    public $listen = [
        ApprovableNotificationCreated::class => [ApprovableNotificationListener::class],
        ApprovableNotificationUpdated::class => [ApprovableNotificationListener::class],
        SocialInteractionDone::class => [SocialInteractionRecieved::class],
        Unfollowed::class => [Follows::class],
        Followed::class => [Follows::class],
        NotificationSent::class => [NotificationSentToApp::class],
        AwaitingReview::class => [SendToAiModerator::class],
        FileSaved::class => [RememberFileMeta::class],
    ];

    /**
     * Bootstrap services.
     */
    public function boot(): void {}

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
