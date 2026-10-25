<?php

namespace App\Services\Composer;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Models\Song;
use App\Models\SongVersion;
use App\Models\User;
use App\Services\Limits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place songs are created, saved and restored, and where their
 * version history is written.
 */
class Songs
{
    /**
     * Autosaves keep at most one version per this many minutes.
     */
    public const VERSION_EVERY_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Song
    {
        Limits::for($user)->ensureBelow(
            Feature::MAX_SONGS,
            $user->songs()->count(),
            'title',
            'Your account can have up to :limit songs.',
        );

        $attributes = $this->attributes($data) + ['title' => 'Untitled song'];

        $song = $user
            ->songs()
            ->create($attributes);

        $this->snapshot($song, $user, SongVersion::AUTOSAVE);

        return $song;
    }

    /**
     * Saves changes made from $revision. A save from an older revision means
     * another window saved first, so it is refused with 409.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Song $song, User $user, array $data, int $revision): Song
    {
        return DB::transaction(function () use ($song, $user, $data, $revision) {
            $current = Song::query()
                ->lockForUpdate()
                ->findOrFail($song->id);

            abort_if(
                $current->revision !== $revision,
                HttpStatus::CONFLICT->value,
                'This song was saved from another window. Load the newer copy or keep yours as a version.',
            );

            $current->fill($this->attributes($data, $current));
            $current->revision++;
            $current->save();

            if ($this->versionDue($current)) {
                $this->snapshot($current, $user, SongVersion::AUTOSAVE);
            }

            return $current;
        });
    }

    public function snapshot(Song $song, ?User $user, string $kind, ?string $name = null): SongVersion
    {
        $version = $song
            ->versions()
            ->create([
                'user_id' => $user?->id,
                'title' => $song->title,
                'body' => $song->body,
                'kind' => $kind,
                'name' => $name,
            ]);

        $song
            ->forceFill(['versioned_at' => $version->created_at])
            ->saveQuietly();

        return $version;
    }

    /**
     * Puts a version's title and lyrics back, keeping the current ones as a version first.
     */
    public function restore(Song $song, SongVersion $version, User $user): Song
    {
        return DB::transaction(function () use ($song, $version, $user) {
            $this->snapshot($song, $user, SongVersion::BEFORE_RESTORE);

            $song->fill(['title' => $version->title, 'body' => $version->body]);
            $song->revision++;
            $song->save();

            return $song;
        });
    }

    /**
     * Deletes autosaved versions older than $cutoff. Named versions and the
     * latest version stay.
     */
    public function prune(Song $song, Carbon $cutoff): int
    {
        $latestId = $song
            ->versions()
            ->max('id');

        return $song
            ->versions()
            ->where('kind', '!=', SongVersion::NAMED)
            ->where('created_at', '<', $cutoff)
            ->where('id', '<', $latestId)
            ->delete();
    }

    protected function versionDue(Song $song): bool
    {
        return ! $song->versioned_at || $song->versioned_at->lte(now()->subMinutes(self::VERSION_EVERY_MINUTES));
    }

    /**
     * Fillable values from a request, with settings merged over the song's own.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data, ?Song $song = null): array
    {
        $attributes = array_intersect_key($data, array_flip(['title', 'body', 'scratchpad', 'notes', 'bpm', 'key', 'beat_sound_id']));

        if (array_key_exists('settings', $data)) {
            $settings = array_merge($song?->editorSettings() ?? Song::SETTINGS, (array) $data['settings']);
            $attributes['settings'] = array_intersect_key($settings, Song::SETTINGS);
        }

        return $attributes;
    }
}
