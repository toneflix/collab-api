<?php

namespace App\Services\Moderation\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * What the AI moderator needs to judge one kind of review, and how its
 * decision is carried out.
 */
abstract class ReviewCase
{
    public function __construct(protected Model $subject) {}

    /**
     * What is being reviewed, like "artist claim".
     */
    abstract public function label(): string;

    abstract public function isPending(): bool;

    /**
     * The rules the moderator follows for this kind of review.
     */
    abstract public function guidelines(): string;

    /**
     * The facts of this case, as plain text.
     */
    abstract public function brief(): string;

    abstract public function approve(?string $note): void;

    abstract public function reject(string $note): void;

    /**
     * A public image the moderator should look at, if any.
     */
    public function image(): ?string
    {
        return null;
    }

    public function subject(): Model
    {
        return $this->subject;
    }

    /**
     * Shorten free text the submitter wrote, so one field can't crowd out
     * the rest of the case.
     */
    protected function clip(?string $text, int $length = 1500): string
    {
        return str((string) $text)->squish()->limit($length)->toString() ?: '(none)';
    }
}
