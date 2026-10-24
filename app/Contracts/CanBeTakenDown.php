<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Reportable content moderators can take down. Taken down content stays
 * visible to its owner only.
 */
interface CanBeTakenDown extends Reportable
{
    public function takedowns(): MorphMany;

    public function isTakenDown(): bool;

    /**
     * Limit a query to content the user owns.
     */
    public function whereOwnedBy(Builder $query, int $userId): void;
}
