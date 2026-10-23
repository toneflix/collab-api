<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Query scope for models the given user has liked.
 * toneflix/social-interactions 1.1+ no longer ships its own query scopes.
 */
trait HasLikedScope
{
    public function scopeLikedBy(Builder $query, Model $interactor): void
    {
        $query->whereHas(
            'socialInteractions',
            fn (Builder $q) => $q->where('interactor_type', $interactor->getMorphClass())
                ->where('interactor_id', $interactor->getKey())
                ->where('liked', true)
        );
    }
}
