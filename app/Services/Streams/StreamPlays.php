<?php

namespace App\Services\Streams;

use App\Enums\Feature;
use App\Models\ProjectCollaborator;
use App\Models\Track;
use App\Models\TrackPlay;
use App\Models\User;

/**
 * Records signed-in plays and decides which ones are paid.
 */
class StreamPlays
{
    /**
     * Record that $listener played $track for $seconds. A listener gives each
     * track at most one paid stream a day.
     */
    public function record(Track $track, User $listener, int $seconds): ?TrackPlay
    {
        if ($seconds <= 0) {
            return null;
        }

        $play = $this->today($track, $listener);
        $play->seconds = max($play->seconds, $seconds);
        $play->paid = $play->paid || $this->pays($track, $listener, $play);
        $play->save();

        return $play;
    }

    /**
     * Whether this play earns the owner a paid stream.
     */
    public function pays(Track $track, User $listener, TrackPlay $play): bool
    {
        $rules = StreamRules::current();
        $owner = $play->owner;

        if ($play->seconds < $rules->minSeconds || ! $owner || $owner->id === $listener->id) {
            return false;
        }

        if (! $owner->feature(Feature::EARN_STREAMS) || $this->worksOn($track, $listener)) {
            return false;
        }

        $paidToday = TrackPlay::query()
            ->where('user_id', $listener->id)
            ->where('played_on', $play->played_on->toDateString())
            ->where('paid', true)
            ->count();

        return $paidToday < $rules->dailyCap;
    }

    /**
     * Collaborators on the studio project don't pay for their own track.
     */
    protected function worksOn(Track $track, User $listener): bool
    {
        $projectId = $track->projectTrack?->project_id;

        return $projectId && ProjectCollaborator::query()
            ->where('project_id', $projectId)
            ->where('user_id', $listener->id)
            ->exists();
    }

    protected function today(Track $track, User $listener): TrackPlay
    {
        $keys = [
            'track_id' => $track->id,
            'user_id' => $listener->id,
            'played_on' => now()->toDateString(),
        ];

        return TrackPlay::query()->firstOrCreate($keys, ['owner_id' => $track->moderationOwnerId()]);
    }
}
