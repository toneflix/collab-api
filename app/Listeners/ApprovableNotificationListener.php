<?php

namespace App\Listeners;

use App\Models\ProjectCollaborator;
use App\Models\User;
use App\Notifications\JoinProjectRequestNotification;
use App\Services\Reputation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Toneflix\ApprovableNotifications\Events\ApprovableNotificationCreated;
use Toneflix\ApprovableNotifications\Events\ApprovableNotificationUpdated;

class ApprovableNotificationListener implements ShouldQueue
{
    // use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ApprovableNotificationCreated|ApprovableNotificationUpdated $event): void
    {
        if ($event instanceof ApprovableNotificationCreated) {
            /**
             * Project collaboration request
             */
            if ($event->notification->actionable instanceof ProjectCollaborator) {
                /** @var User $recipient */
                $recipient = $event->notification->notifiable;

                $recipient->notify(new JoinProjectRequestNotification(
                    $event->notification->actionable,
                    $event->notification->notifier,
                    $event->notification->data['action']
                ));
            }
        } elseif ($event instanceof ApprovableNotificationUpdated) {
            /**
             * Project collaboration request approval/rejection
             */
            if ($event->notification->actionable instanceof ProjectCollaborator) {
                /** @var User $user */
                $user = $event->notification->notifier;
                $collaborator = $event->notification->actionable;

                if ($event->notification->approved) {
                    // Approve the user's request
                    $collaborator->accepted = true;
                    $collaborator->saveQuietly();

                    $direction = $event->notification->data['action'] === 'invitation'
                        ? 'invitation_accepted'
                        : 'request_approved';

                    $user->notify(new JoinProjectRequestNotification(
                        $collaborator,
                        $event->notification->notifiable,
                        $direction
                    ));

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
