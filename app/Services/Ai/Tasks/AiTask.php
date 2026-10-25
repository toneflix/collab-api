<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;

/**
 * Something an AI job does, like generating music or splitting stems.
 */
interface AiTask
{
    /**
     * Credits a job with this input uses.
     *
     * @param  array<string, mixed>  $input
     */
    public function credits(array $input): int;

    /**
     * A short line for the credit history, like "Music: lofi beat".
     *
     * @param  array<string, mixed>  $input
     */
    public function describe(array $input): string;

    /**
     * The engine a job with this input runs on.
     *
     * @param  array<string, mixed>  $input
     */
    public function engine(array $input): AiEngine;

    /**
     * Do the work and return what it made, to keep on the job.
     *
     * @return array<string, mixed>
     */
    public function handle(AiJob $job): array;
}
