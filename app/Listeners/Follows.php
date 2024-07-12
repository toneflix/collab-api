<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Reputation;

class Follows
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
     * @param \Overtrue\LaravelFollow\Events\Followed|\Overtrue\LaravelFollow\Events\Unfollowed $event
     */
    public function handle(object $event): void
    {
        /** @var \App\Models\Artist|\App\Models\User */
        $followable = $event->followable_type::find($event->followable_id);

        $reputation = new Reputation($followable, 'profile_follows');

        if ($followable) {
            if ($event instanceof \Overtrue\LaravelFollow\Events\Followed) {
                // Add reputation for the owner
                $reputation->add();
            } else {
                // Remove reputation for the owner
                $reputation->remove();
            }
        }
    }
}
