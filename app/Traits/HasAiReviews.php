<?php

namespace App\Traits;

use App\Models\AiReview;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Something the AI moderator can review.
 */
trait HasAiReviews
{
    public function aiReviews(): MorphMany
    {
        return $this->morphMany(AiReview::class, 'subject');
    }

    public function latestAiReview(): MorphOne
    {
        return $this->morphOne(AiReview::class, 'subject')->latestOfMany();
    }
}
