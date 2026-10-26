<?php

namespace App\Services\Ai\Edit;

use App\Enums\AiEngine;

/**
 * An engine that writes one section of a clip of audio again from a prompt,
 * keeping the rest of the clip as it was.
 */
interface SectionEditor
{
    public function engine(): AiEngine;

    /**
     * The model a job with this input runs, kept on the new stem, like "suno/V5_5".
     *
     * @param  array<string, mixed>  $input
     */
    public function model(array $input): string;

    /**
     * The shortest section it writes again, in seconds.
     */
    public function minSeconds(): float;

    /**
     * The largest share of the clip the section may take, from 0 to 1.
     */
    public function maxShare(): float;

    /**
     * Admin settings a job keeps from when it started.
     *
     * @return array<string, mixed>
     */
    public function jobSettings(): array;

    /**
     * Write the clip's section between two times again, and return the
     * edited clip as a local temporary file.
     *
     * @param  array<string, mixed>  $input
     */
    public function edit(string $clip, float $from, float $to, array $input): string;
}
