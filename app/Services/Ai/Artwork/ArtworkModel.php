<?php

namespace App\Services\Ai\Artwork;

use App\Enums\AiEngine;

/**
 * A model that draws images from a prompt.
 */
interface ArtworkModel
{
    public function engine(): AiEngine;

    /**
     * The model's ID on its engine, kept on the job.
     */
    public function model(): string;

    /**
     * Draw images from a prompt and return them as local temporary files.
     * The images come close to the ratio; ArtworkImages crops them to it.
     *
     * @param  array{0: int, 1: int}  $ratio
     * @return list<string>
     */
    public function generate(string $prompt, array $ratio, int $count): array;
}
