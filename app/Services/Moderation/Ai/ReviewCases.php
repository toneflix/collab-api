<?php

namespace App\Services\Moderation\Ai;

use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\Track;
use App\Services\Artists\ArtistClaims;
use App\Services\Releases\ReleaseVersions;
use App\Services\Releases\TrackOrigins;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Builds the review case for each kind of item the AI moderator handles.
 */
class ReviewCases
{
    public function __construct(
        protected ArtistClaims $claims,
        protected ReleaseVersions $versions,
        protected TrackOrigins $origins,
    ) {}

    public function for(Model $subject): ReviewCase
    {
        return match (true) {
            $subject instanceof ArtistClaim => new ArtistClaimCase($subject, $this->claims),
            $subject instanceof Release => new ReleaseCase($subject, $this->versions),
            $subject instanceof Track => new TrackOriginCase($subject, $this->origins),
            default => throw new InvalidArgumentException('The AI moderator can\'t review ' . $subject::class . '.'),
        };
    }
}
