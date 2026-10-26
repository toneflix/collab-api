<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Artist;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Song;
use App\Models\Track;
use App\Services\Agent\AgentArtwork;
use App\Services\Releases\ReleaseVersions;

/**
 * Releases an album's project with all its tracks: a draft for the owner to
 * finish, or published when the run asks for it. The album's cover goes on
 * the release and its songs.
 */
class ReleaseAlbum implements AgentAction
{
    public function __construct(
        protected ReleaseVersions $versions,
        protected AgentArtwork $artwork,
    ) {}

    public function advance(AgentStep $step): bool
    {
        if ($step->output['release_id'] ?? null) {
            return true;
        }

        $run = $step->run;
        $input = $step->input;
        $publish = (bool) $run->option('publish', false);
        $label = trim((string) $run->option('label')) ?: (string) config('app.name');
        $artist = Artist::query()->findOrFail($step->from($input['artist'], 'artist_id'));

        /** @var Release $release */
        $release = $run->owner
            ->releases()
            ->make();
        $release->title = $input['title'];
        $release->description = $input['description'] ?? '';
        $release->genre_id = $input['genre_id'];
        $release->artist_id = $artist->id;
        $release->project_id = $step->from($input['project'], 'project_id');
        $release->composer = $artist->name;
        $release->publisher = $label;
        $release->record_label = $label;
        $release->pline = '℗ ' . now()->year . ' ' . $label;
        $release->isrc = '';
        $release->iswc = '';
        $release->draft = ! $publish;
        $release->privacy = 'public';
        $release->explicit = false;
        $release->contains_music = true;
        $release->release_date = now();
        $release->downloadable = false;
        $release->save();

        foreach ($input['tracks'] as $source) {
            $this->addTrack($release, $artist, (int) $step->from($source, 'project_track_id'));
        }

        $this->cover($step, $release);

        if ($publish) {
            $this->versions->published($release);
        }

        $step->remember(['release_id' => $release->id, 'artist_id' => $artist->id, 'published' => $publish]);

        return true;
    }

    /**
     * Put the album's cover on the release and on each of its songs.
     */
    protected function cover(AgentStep $step, Release $release): void
    {
        $art = $step->input['artwork'] ?? null;
        $file = $art ? $step->from($art, 'file') : null;

        if (! $file || ! $this->artwork->apply($release, $file)) {
            return;
        }

        $songIds = collect($step->input['tracks'])
            ->filter(fn(string $source) => str_starts_with($source, 'song:'))
            ->map(fn(string $source) => $step->from($source, 'song_id'))
            ->filter()
            ->all();

        $songs = Song::query()
            ->whereKey($songIds)
            ->whereNull('image')
            ->get();

        foreach ($songs as $song) {
            $this->artwork->apply($song, $file);
        }
    }

    protected function addTrack(Release $release, Artist $artist, int $projectTrackId): void
    {
        $projectTrack = ProjectTrack::query()->findOrFail($projectTrackId);

        /** @var Track $track */
        $track = $release
            ->tracks()
            ->firstOrNew(['project_track_id' => $projectTrack->id]);
        $track->title = $projectTrack->title;
        $track->genre_id = $projectTrack->genre_id;
        $track->privacy = 'public';
        $track->description = $projectTrack->description;
        $track->slug = $track->gusm($track->title, '-');
        $track->duration = $projectTrack->duration;
        $track->saveQuietly();

        $track
            ->artists()
            ->sync([$artist->id => ['type' => 'primary']]);

        $track->queueSoundChecks(false);
    }
}
