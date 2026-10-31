<?php

namespace App\Services\Ai\Tasks;

use App\Models\AiJob;

/**
 * A task that puts what it was working on back in order when its job fails,
 * after the credits are refunded.
 */
interface HandlesFailure
{
    public function failed(AiJob $job): void;
}
