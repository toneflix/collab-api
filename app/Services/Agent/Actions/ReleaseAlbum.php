<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\Artist;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Services\Releases\ReleaseVersions;

/**
 * Releases an album's project with all its tracks: a draft for the owner to
 * finish, or published when the run asks for it.
 */
class ReleaseAlbum implements AgentAction
{
    public function __construct(protected ReleaseVersions $versions) {}

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

        if ($publish) {
            $this->versions->published($release);
        }

        $step->remember(['release_id' => $release->id, 'artist_id' => $artist->id, 'published' => $publish]);

        return true;
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
