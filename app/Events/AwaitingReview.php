<?php

namespace App\Events;

use App\Models\ArtistClaim;
use App\Models\Release;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A claim or release started waiting for a moderator.
 */
class AwaitingReview
{
    use Dispatchable;

    public function __construct(public ArtistClaim|Release $subject) {}
}
