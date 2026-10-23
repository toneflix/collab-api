<?php

namespace App\Listeners;

use App\Models\Project;
use App\Models\Release;
use App\Models\Track;
use App\Services\Reputation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Toneflix\SocialInteractions\Events\SocialInteractionDone;

class SocialInteractionRecieved
{
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
     * @param SocialInteractionDone $event
     */
    public function handle(SocialInteractionDone $event): void
    {
        // Compute the reputation owner and type
        if ($event->interaction->interactable instanceof Track) {
            $owner = $event->interaction->interactable->release->user;
            $mapActions = [
                'votes' => null,
                'liked' => 'track_likes',
                'saved' => 'track_saves',
            ];
        } else if ($event->interaction->interactable instanceof Release) {
            $owner = $event->interaction->interactable->user;
            $mapActions = [
                'votes' => null,
                'liked' => 'release_likes',
                'saved' => null,
            ];
        } else if ($event->interaction->interactable instanceof Project) {
            $owner = $event->interaction->interactable->user;
            $mapActions = [
                'votes' => 'project_props',
                'liked' => null,
                'saved' => null,
            ];
        }

        // Add reputation for the owner
        if (!empty($owner) && !empty($mapActions[$event->action])) {
            (new Reputation($owner, $mapActions[$event->action]))->add();
        }
    }
}
