<?php

namespace App\Services\Agent;

use RuntimeException;

/**
 * A step that can't go on. The message is safe to show the run's owner; the
 * detail, like an AI provider's error, is for admins.
 */
class AgentStepFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }
}
