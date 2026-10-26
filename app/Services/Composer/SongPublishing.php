<?php

namespace App\Services\Composer;

use App\Models\Song;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Publishes and unpublishes songs and sets what others may do with them.
 * A published song keeps the address it got the first time.
 */
class SongPublishing
{
    /**
     * @param  array{published?: bool, allow_forks?: bool, allow_projects?: bool}  $data
     */
    public function update(Song $song, array $data): Song
    {
        $publish = (bool) ($data['published'] ?? $song->isPublished());

        if ($publish && $song->lyricLines() === []) {
            throw ValidationException::withMessages(['published' => 'Write some lyrics before publishing this song.']);
        }

        if ($publish && $song->isTakenDown()) {
            throw ValidationException::withMessages(['published' => 'This song was taken down and can\'t be published.']);
        }

        $song->forceFill([
            'published_at' => $publish ? ($song->published_at ?? now()) : null,
            'allow_forks' => (bool) ($data['allow_forks'] ?? $song->allow_forks),
            'allow_projects' => (bool) ($data['allow_projects'] ?? $song->allow_projects),
        ]);

        if ($publish && ! $song->slug) {
            $song->slug = $this->slug($song);
        }

        $song->saveQuietly();

        return $song;
    }

    protected function slug(Song $song): string
    {
        $base = Str::slug(Str::limit($song->title, 120, '')) ?: 'song';

        return "{$base}-{$song->id}";
    }
}
