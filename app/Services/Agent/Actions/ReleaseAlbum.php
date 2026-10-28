<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Artist;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Song;
use App\Models\Track;
use App\Models\User;
use App\Services\Agent\AgentArtwork;
use App\Services\Releases\ReleaseVersions;
use Illuminate\Support\Facades\Gate;

/**
 * Releases a project with its tracks through the usual release rules: an
 * album the run made, or one of the owner's existing projects as a new
 * version. It's a draft for the owner to finish, or published when the run
 * asks for it, retiring the earlier versions when the plan says so. The
 * cover goes on the release and its songs.
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
        $owner = $run->owner;
        $input = $step->input;
        $publish = (bool) $run->option('publish', false);
        $label = trim((string) $run->option('label')) ?: (string) config('app.name');
        $project = Project::query()->findOrFail($input['project_id'] ?? $step->from($input['project'], 'project_id'));
        $artistId = isset($input['artist']) ? $step->from($input['artist'], 'artist_id') : ($input['artist_id'] ?? null);
        $artist = Artist::query()->findOrFail($artistId);

        Gate::forUser($owner)->authorize('release', $project);

        $retire = ($input['retire'] ?? false) ? $this->liveVersions($project) : [];

        $release = $this->draft($owner, $project);
        $release->title = $input['title'];
        $release->version = ($input['version'] ?? null) ?: null;
        $release->description = $input['description'] ?? '';
        $release->genre_id = $input['genre_id'];
        $release->artist_id = $artist->id;
        $release->project_id = $project->id;
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

        foreach ($this->trackIds($step) as $trackId) {
            $this->addTrack($release, $artist, $trackId);
        }

        $this->cover($step, $release);

        if ($publish) {
            $this->versions->published($release, $retire);
        }

        $step->remember(['release_id' => $release->id, 'artist_id' => $artist->id, 'project_id' => $project->id, 'published' => $publish]);

        return true;
    }

    /**
     * The project's unfinished version, picked up before starting a new one.
     */
    protected function draft(User $owner, Project $project): Release
    {
        /** @var Release|null $draft */
        $draft = $owner
            ->releases()
            ->where('project_id', $project->id)
            ->where('draft', true)
            ->latest('id')
            ->first();

        return $draft ?? $owner
            ->releases()
            ->make();
    }

    /**
     * The project tracks the release carries: those the run made, or the
     * existing project's tracks it was planned with.
     *
     * @return list<int>
     */
    protected function trackIds(AgentStep $step): array
    {
        $made = array_map(fn(string $source) => (int) $step->from($source, 'project_track_id'), $step->input['tracks'] ?? []);

        return [...$made, ...array_map('intval', $step->input['track_ids'] ?? [])];
    }

    /**
     * Ids of the project's published releases that are still live, which a
     * new version replaces when the run asks for it.
     *
     * @return list<int>
     */
    protected function liveVersions(Project $project): array
    {
        return $project
            ->releases()
            ->where('draft', false)
            ->whereNull('retired_at')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
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

        $songIds = collect($step->input['tracks'] ?? [])
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
