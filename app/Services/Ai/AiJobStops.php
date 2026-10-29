<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Stop requests for running AI jobs. A worker runs a job inside watch(), and
 * the provider clients call check() while they wait, which throws once the
 * job was asked to stop.
 */
class AiJobStops
{
    /** Hours a stop request is kept */
    public const KEEP_HOURS = 3;

    /** The job this worker is running */
    protected ?int $current = null;

    public function request(AiJob $job): void
    {
        Cache::put($this->key($job->id), true, now()->addHours(self::KEEP_HOURS));
    }

    public function requested(AiJob $job): bool
    {
        return Cache::has($this->key($job->id));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function watch(AiJob $job, Closure $work): mixed
    {
        $this->current = $job->id;

        try {
            return $work();
        } finally {
            $this->current = null;
            Cache::forget($this->key($job->id));
        }
    }

    /**
     * @throws AiJobStopped
     */
    public function check(): void
    {
        if ($this->current && Cache::has($this->key($this->current))) {
            throw new AiJobStopped;
        }
    }

    protected function key(int $id): string
    {
        return "ai-job:{$id}:stop";
    }
}
