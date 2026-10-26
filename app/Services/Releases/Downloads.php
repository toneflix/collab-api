<?php

namespace App\Services\Releases;

use App\Enums\Feature;
use App\Helpers\Providers;
use App\Models\Track;
use App\Models\User;

/**
 * Who may download a track.
 *
 * Owners and people who bought a paid release can always download it. Everyone
 * else needs downloads in their plan, the creator's opt-in on the release and
 * the admin setting turned on.
 */
class Downloads
{
    public static function enabled(): bool
    {
        return filter_var(Providers::config('track_downloads', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * Whether the track is offered for download to people with downloads in their plan.
     */
    public static function offered(Track $track): bool
    {
        return self::enabled() && (bool) ($track->release?->downloadable ?? false);
    }

    public static function bought(Track $track): bool
    {
        return $track->release !== null && $track->release->price > 0;
    }

    /**
     * Whether the track can be downloaded at all by this user, plan aside.
     */
    public static function available(Track $track, ?User $user): bool
    {
        return self::bought($track) || self::offered($track) || (bool) $track->release?->isOwnedBy($user);
    }

    public static function allowedFor(Track $track, ?User $user): bool
    {
        if (! $user || ! $track->isUnlockedFor($user)) {
            return false;
        }

        if (self::bought($track) || $track->release?->isOwnedBy($user)) {
            return true;
        }

        return self::offered($track) && $user->feature(Feature::DOWNLOADS);
    }
}
