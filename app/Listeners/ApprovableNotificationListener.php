<?php

namespace App\Listeners;

use App\Models\ProjectCollaborator;
use App\Notifications\JoinProjectRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use ToneflixCode\ApprovableNotifications\Events\ApprovableNotificationCreated;
use ToneflixCode\ApprovableNotifications\Events\ApprovableNotificationUpdated;
use ToneflixCode\ApprovableNotifications\Models\Notification;

class ApprovableNotificationListener implements ShouldQueue
{
    //use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param object{notification:Notification}
     */
    public function handle(ApprovableNotificationCreated|ApprovableNotificationUpdated $event): void
    {
        if ($event instanceof ApprovableNotificationCreated) {
            if ($event->notification->actionable instanceof ProjectCollaborator) {

                $collaborator = $event->notification->actionable;
                /** @var \App\Models\User $user */
                $user = $collaborator->user;

                // Notify the user
                $user->notify(new JoinProjectRequestNotification(
                    $event->notification,
                    $event->notification->data['action']
                ));
            }
        } elseif ($event instanceof ApprovableNotificationUpdated) {
            if ($event->notification->actionable instanceof ProjectCollaborator) {
                /** @var \App\Models\User $user */
                $user = $event->notification->notifier;
                $collaborator = $event->notification->actionable;

                // Approve the user's request
                if ($event->notification->approved) {
                    $collaborator->accepted = true;
                    $collaborator->saveQuietly();

                    // Notify the user
                    $user->notify(new JoinProjectRequestNotification(
                        $event->notification,
                        $event->notification->data['action'] === 'invitation'
                            ? 'invitation_accepted'
                            : 'request_approved'
                    ));
                }
            }
        }
    }
}
