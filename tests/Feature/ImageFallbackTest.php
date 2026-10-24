<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Tests\TestCase;

class ImageFallbackTest extends TestCase
{
    public function test_missing_images_fall_back_to_shipped_placeholders(): void
    {
        $user = new User(['image' => 'deleted-file.png']);
        $release = new Release;

        $this->assertStringEndsWith('/defaults/avatar.svg', $user->images['image']);
        $this->assertStringEndsWith('/defaults/artwork.svg', $release->files['artwork']);
    }

    public function test_every_placeholder_file_exists(): void
    {
        foreach (config('toneflix-fileable.collections') as $name => $collection) {
            $default = $collection['default'] ?? null;

            if (is_string($default) && str_contains($default, '/defaults/')) {
                $this->assertFileExists(public_path('defaults/'.basename($default)), "Missing placeholder for {$name}");
            }
        }
    }
}
