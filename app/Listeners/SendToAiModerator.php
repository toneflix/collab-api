<?php

namespace App\Listeners;

use App\Events\AwaitingReview;
use App\Services\Moderation\Ai\AiModerator;

/**
 * Hands new claims and releases to the AI moderator when automatic review
 * is on for their kind.
 */
class SendToAiModerator
{
    public function __construct(protected AiModerator $moderator) {}

    public function handle(AwaitingReview $event): void
    {
        $this->moderator->sendIfAuto($event->subject);
    }
}
