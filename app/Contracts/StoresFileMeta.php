<?php

namespace App\Contracts;

/**
 * A model that keeps the size and mime type of its files, so reading them
 * never asks the disk.
 */
interface StoresFileMeta
{
    /**
     * The file fields and the fileable collection each one is stored in.
     *
     * @return array<string, string>
     */
    public function fileMetaFields(): array;

    /**
     * Save the size and mime type of any file that changed since the last save.
     */
    public function rememberFileMeta(): void;
}
