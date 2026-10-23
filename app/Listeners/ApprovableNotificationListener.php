<?php

namespace App\Listeners;

use App\Models\ProjectCollaborator;
use App\Notifications\JoinProjectRequestNotification;
use App\Services\Reputation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Toneflix\ApprovableNotifications\Events\ApprovableNotificationCreated;
use Toneflix\ApprovableNotifications\Events\ApprovableNotificationUpdated;
use Toneflix\ApprovableNotifications\Models\Notification;

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
     * @param ApprovableNotificationCreated|ApprovableNotificationUpdated $event
     */
    public function handle(ApprovableNotificationCreated|ApprovableNotificationUpdated $event): void
    {
        if ($event instanceof ApprovableNotificationCreated) {
            /**
             * Project collaboration request
             */
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
            /**
             * Project collaboration request approval/rejection
             */
            if ($event->notification->actionable instanceof ProjectCollaborator) {
                /** @var \App\Models\User $user */
                $user = $event->notification->notifier;
                $collaborator = $event->notification->actionable;

                if ($event->notification->approved) {
                    // Approve the user's request
                    $collaborator->accepted = true;
                    $collaborator->saveQuietly();

                    $direction = $event->notification->data['action'] === 'invitation'
                        ? 'invitation_accepted'
                        : 'request_approved';

                    // Notify the user
                    $user->notify(new JoinProjectRequestNotification($event->notification, $direction));

                    // Add reputation for the users
                    (new Reputation($user, "collab_{$direction}"))->add();
                } else {
                    // Reject the user's request
                    $collaborator->deleteQuietly();
                }
                $event->notification->deleteQuietly();
            }
        }
    }
}
