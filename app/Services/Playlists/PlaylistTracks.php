<?php

namespace App\Services\Playlists;

use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds and removes a playlist's tracks, keeping their order and the track
 * the playlist's cover comes from in step.
 */
class PlaylistTracks
{
    /** Most tracks a playlist holds */
    public const MAX_TRACKS = 500;

    /** Most playlists one user keeps */
    public const MAX_PLAYLISTS = 200;

    public function __construct(
        protected Playlist $playlist,
    ) {}

    /**
     * Refuse a new playlist when the user already keeps as many as allowed.
     */
    public static function ensureRoomFor(User $user): void
    {
        $count = $user
            ->playlists()
            ->count();

        if ($count >= self::MAX_PLAYLISTS) {
            throw ValidationException::withMessages([
                'title' => __('You can keep up to :max playlists.', ['max' => self::MAX_PLAYLISTS]),
            ]);
        }
    }

    /**
     * Put a track at the end of the playlist. A track already in it stays where it is.
     *
     * @return bool Whether the track was added.
     */
    public function add(Track $track): bool
    {
        return DB::transaction(function () use ($track) {
            $playlist = Playlist::query()
                ->lockForUpdate()
                ->findOrFail($this->playlist->id);

            $exists = DB::table('playlist_track')
                ->where('playlist_id', $playlist->id)
                ->where('track_id', $track->id)
                ->exists();

            if ($exists) {
                return false;
            }

            $count = DB::table('playlist_track')
                ->where('playlist_id', $playlist->id)
                ->count();

            if ($count >= self::MAX_TRACKS) {
                throw ValidationException::withMessages([
                    'track_id' => __('A playlist can hold up to :max tracks.', ['max' => self::MAX_TRACKS]),
                ]);
            }

            $position = (int) DB::table('playlist_track')
                ->where('playlist_id', $playlist->id)
                ->max('position') + 1;

            $playlist
                ->tracks()
                ->attach($track->id, ['position' => $position]);

            $playlist->cover_track_id ??= $track->id;
            $playlist->touch();

            $this->playlist->setRawAttributes($playlist->getAttributes(), true);

            return true;
        });
    }

    /**
     * Take a track out of the playlist.
     *
     * @return bool Whether the track was in it.
     */
    public function remove(Track $track): bool
    {
        return DB::transaction(function () use ($track) {
            $playlist = Playlist::query()
                ->lockForUpdate()
                ->findOrFail($this->playlist->id);

            $removed = $playlist
                ->tracks()
                ->detach($track->id);

            if (! $removed) {
                return false;
            }

            if ((int) $playlist->cover_track_id === (int) $track->id) {
                $playlist->cover_track_id = $playlist
                    ->tracks()
                    ->value('tracks.id');
            }

            $playlist->save();

            $this->playlist->setRawAttributes($playlist->getAttributes(), true);

            return true;
        });
    }
}
