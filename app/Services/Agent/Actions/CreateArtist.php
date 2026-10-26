<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Artist;

/**
 * Adds an artist to the owner's account, or picks the owner's artist that
 * already has the name.
 */
class CreateArtist implements AgentAction
{
    public function advance(AgentStep $step): bool
    {
        $owner = $step->run->owner;
        $name = (string) $step->input['name'];

        /** @var Artist|null $artist */
        $artist = $owner
            ->artists()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $artist) {
            $artist = $owner
                ->artists()
                ->make();
            $artist->name = $name;
            $artist->about = $step->input['about'] ?: null;
            $artist->save();
        }

        $step->remember(['artist_id' => $artist->id, 'existing' => ! $artist->wasRecentlyCreated]);

        return true;
    }
}
