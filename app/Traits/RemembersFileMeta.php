<?php

namespace App\Traits;

use App\Services\Storage\StoredFile;

/**
 * Keeps each file's size and mime type in the `file_meta` column, keyed by
 * file field, for models that implement StoresFileMeta.
 *
 * @property array<string, array{name: string, size: int, mime: string|null}>|null $file_meta
 */
trait RemembersFileMeta
{
    public function initializeRemembersFileMeta(): void
    {
        $this->mergeCasts([
            'file_meta' => 'array',
        ]);
    }

    public function rememberFileMeta(): void
    {
        $stored = $this->file_meta ?? [];
        $meta = $stored;

        foreach ($this->fileMetaFields() as $field => $collection) {
            $name = $this->getAttribute($field);

            if (! $name) {
                unset($meta[$field]);

                continue;
            }

            if (($meta[$field]['name'] ?? null) === $name) {
                continue;
            }

            $file = StoredFile::describe($collection, $name);

            if ($file) {
                $meta[$field] = $file;
            } else {
                unset($meta[$field]);
            }
        }

        if ($meta !== $stored) {
            $this->file_meta = $meta;
            $this->saveQuietly();
        }
    }

    /**
     * The saved size in bytes of a file field, when it is known.
     */
    public function fileSize(string $field): ?int
    {
        return $this->storedFileMeta($field)['size'] ?? null;
    }

    /**
     * The saved mime type of a file field, when it is known.
     */
    public function fileMime(string $field): ?string
    {
        return $this->storedFileMeta($field)['mime'] ?? null;
    }

    /**
     * @return array{name: string, size: int, mime: string|null}|null
     */
    protected function storedFileMeta(string $field): ?array
    {
        $meta = $this->file_meta[$field] ?? null;

        return ($meta['name'] ?? null) === $this->getAttribute($field) ? $meta : null;
    }
}
