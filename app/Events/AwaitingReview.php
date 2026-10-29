<?php

namespace App\Events;

use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A claim, a release, or a direct upload's origin started waiting for a moderator.
 */
class AwaitingReview
{
    use Dispatchable;

    public function __construct(public ArtistClaim|Release|Track $subject) {}
}
