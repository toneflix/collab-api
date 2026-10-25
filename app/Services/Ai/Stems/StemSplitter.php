<?php

namespace App\Services\Ai\Stems;

/**
 * Splits a song into parts, like vocals and drums, on one AI engine.
 */
interface StemSplitter
{
    /**
     * Split a local file and return each part as a local file, keyed by the
     * part's name. The source's AI details can let the engine skip an upload.
     *
     * @param  array<string, mixed>  $ai  The song's `ai` meta
     * @return array<string, string>
     */
    public function split(string $path, array $ai = []): array;

    /**
     * The format of the parts it returns, "wav" or "mp3".
     */
    public function format(): string;

    /**
     * The model kept on each stem it makes.
     */
    public function model(): string;
}
