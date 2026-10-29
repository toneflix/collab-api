<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Thrown inside a running AI job that was asked to stop.
 */
class AiJobStopped extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The job was stopped.');
    }
}
