<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Content people can report to moderators.
 */
interface Reportable
{
    public function reports(): MorphMany;

    /**
     * The id of the person who owns this content.
     */
    public function moderationOwnerId(): ?int;

    /**
     * What moderators and owners see about this content.
     *
     * @return array{title: string, subtitle: ?string, image: ?string, link: ?string}
     */
    public function moderationSummary(): array;
}
