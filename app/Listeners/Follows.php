<?php

namespace App\Listeners;

use App\Models\Artist;
use App\Models\User;
use App\Services\Reputation;
use Overtrue\LaravelFollow\Events\Followed;
use Overtrue\LaravelFollow\Events\Unfollowed;

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
     * @param  Followed|Unfollowed  $event
     */
    public function handle(object $event): void
    {
        /** @var Artist|User */
        $followable = $event->followable_type::find($event->followable_id);

        $reputation = new Reputation($followable, 'profile_follows');

        if ($followable) {
            if ($event instanceof Followed) {
                // Add reputation for the owner
                $reputation->add();
            } else {
                // Remove reputation for the owner
                $reputation->remove();
            }
        }
    }
}
