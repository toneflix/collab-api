<?php

namespace App\Services\Releases;

use App\Enums\Feature;
use App\Helpers\Providers;
use App\Models\Track;
use App\Models\User;

/**
 * Who may download a track.
 *
 * Owners and people who bought a paid release can always download it. When
 * the creator opened a track of a live release to everyone and their plan or
 * an admin grant includes open downloads, anyone can. Everyone else needs
 * downloads in their plan and the creator's opt-in on the release. Apart from
 * owners and buyers, the admin setting has to be on.
 */
class Downloads
{
    public static function enabled(): bool
    {
        return filter_var(Providers::config('track_downloads', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * Whether the track's owner may open its download to everyone.
     */
    public static function canOpen(Track $track): bool
    {
        return (bool) $track->release?->user?->feature(Feature::OPEN_DOWNLOADS);
    }

    /**
     * Whether anyone, signed in or not, can download the track.
     */
    public static function open(Track $track): bool
    {
        $release = $track->release;

        return self::enabled()
            && $track->open_download
            && $release !== null
            && ! $release->draft
            && ! $release->isRetired()
            && ! $release->isInReview()
            && self::canOpen($track);
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
        return self::bought($track)
            || self::offered($track)
            || self::open($track)
            || (bool) $track->release?->isOwnedBy($user);
    }

    public static function allowedFor(Track $track, ?User $user): bool
    {
        if (! $track->isUnlockedFor($user)) {
            return false;
        }

        if (self::open($track)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if (self::bought($track) || $track->release?->isOwnedBy($user)) {
            return true;
        }

        return self::offered($track) && $user->feature(Feature::DOWNLOADS);
    }
}
