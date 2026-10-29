<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\Lyric;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the listener-facing descriptions of what the agent makes, and fills
 * in the ones an existing project or release is missing: the project, its
 * releases and their tracks.
 */
class AgentDescriptions
{
    /** How every description the agent writes reads */
    public const STYLE = 'Descriptions are for listeners: what the music sounds and feels like, what it is about and the moments it fits, warm and inviting, like a fan sharing it with friends. Never mention prices, plans, payments, earnings or AI, never name real artists, and use no hashtags, emoji or quotes.';

    /** Most tracks one ask describes */
    public const MAX_TRACKS = 30;

    public function __construct(protected AgentWriter $writer) {}

    /**
     * Describe whatever of a project has no description. Released tracks
     * take their project track's description, and releases the project's.
     */
    public function fill(AgentRun $run, Project $project): void
    {
        $project->loadMissing('release.artist', 'release.genre');

        $tracks = self::blank($project->tracks(), 'description')
            ->with(['lyric', 'song', 'artist', 'genre'])
            ->oldest('id')
            ->limit(self::MAX_TRACKS)
            ->get();

        $this->write($run, $project, $tracks, $project->release?->title ?: $project->title, $project->release?->artist?->name);

        $releases = $project
            ->releases()
            ->withoutGlobalScopes();
        $released = Track::query()
            ->withoutGlobalScopes()
            ->whereIn('release_id', (clone $releases)->select('id'))
            ->whereNotNull('project_track_id');

        self::blank($released, 'description')->update([
            'description' => DB::raw('(select description from project_tracks where project_tracks.id = tracks.project_track_id)'),
        ]);

        if (! self::empty($project->description)) {
            self::blank($releases, 'description')->update(['description' => $project->description]);
        }
    }

    /**
     * Describe a release with no project, and its tracks without one.
     */
    public function fillRelease(AgentRun $run, Release $release): void
    {
        $release->loadMissing('artist', 'genre');

        $own = $release
            ->tracks()
            ->withoutGlobalScopes();

        $tracks = self::blank($own, 'description')
            ->with('lyric')
            ->oldest('id')
            ->limit(self::MAX_TRACKS)
            ->get();

        $this->write($run, $release, $tracks, $release->title, $release->artist?->name);
    }

    /**
     * Ask for the descriptions the album and its tracks are missing and save them.
     *
     * @param  Collection<int, ProjectTrack|Track>  $tracks
     */
    protected function write(AgentRun $run, Project|Release $album, Collection $tracks, string $title, ?string $artist): void
    {
        $describeAlbum = self::empty($album->description);

        if (! $describeAlbum && $tracks->isEmpty()) {
            return;
        }

        $genre = $album instanceof Release ? $album->genre?->title : ($album->release?->genre?->title ?? $tracks->first()?->genre?->title);

        $answer = $this->writer->json($run, 'descriptions', implode("\n", [
            'You write descriptions of albums and tracks for a community music streaming platform.',
            self::STYLE,
            'An album gets two or three sentences, a track one or two. Base them on the titles, sound and lyrics you are given.',
            'Answer with JSON only, no other text: {"album": "", "tracks": [{"id": 1, "description": ""}]}',
        ]), implode("\n", array_filter([
            'Album: ' . $title,
            $artist ? 'Artist: ' . $artist : null,
            $genre ? 'Genre: ' . $genre : null,
            $describeAlbum ? 'Describe the album.' : 'The album has its description; leave "album" empty.',
            $tracks->isNotEmpty() ? "Describe these tracks:\n" . $tracks
                ->map(fn(ProjectTrack|Track $track) => $this->trackLine($track))
                ->join("\n") : 'Leave "tracks" empty.',
        ])), 300 + 120 * $tracks->count());

        $text = fn(mixed $value, int $max) => is_string($value) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, $max) : '';

        $written = collect(is_array($answer['tracks'] ?? null) ? $answer['tracks'] : [])
            ->filter(fn($entry) => is_array($entry) && is_scalar($entry['id'] ?? null))
            ->mapWithKeys(fn(array $entry) => [(int) $entry['id'] => $text($entry['description'] ?? null, 1000)]);

        foreach ($tracks as $track) {
            if (($written[$track->id] ?? '') !== '') {
                $this->save($track, $written[$track->id]);
            }
        }

        $about = $text($answer['album'] ?? null, 2000);

        if ($describeAlbum && $about !== '') {
            $this->save($album, $about);
        }
    }

    /**
     * A track as the writer sees it: its title, sound and opening lyrics.
     */
    protected function trackLine(ProjectTrack|Track $track): string
    {
        $sound = $track instanceof ProjectTrack ? $track->song?->notes : null;
        $lyrics = collect($track->lyric instanceof Lyric ? $track->lyric->lines : [])
            ->pluck('text')
            ->map(fn($line) => trim((string) $line))
            ->reject(fn(string $line) => $line === '' || preg_match('/^\[[^\]]*\]$/u', $line))
            ->take(6)
            ->join(' / ');

        return collect([
            "- id {$track->id}: \"{$track->title}\"",
            $sound ? 'sound: ' . mb_substr($sound, 0, 300) : null,
            $lyrics !== '' ? 'lyrics: ' . mb_substr($lyrics, 0, 400) : 'instrumental or no lyrics',
        ])
            ->filter()
            ->join('; ');
    }

    protected function save(Model $model, string $description): void
    {
        $model->description = $description;
        $model->saveQuietly();
    }

    /**
     * Limit a project query to projects with anything missing a description:
     * the project, one of its tracks, one of its releases or a released track.
     */
    public static function whereMissing(Builder $query): void
    {
        $releases = Release::query()
            ->withoutGlobalScopes()
            ->select('project_id')
            ->whereNotNull('project_id');

        $tracks = ProjectTrack::query()->select('project_id');
        $undescribed = self::blank(clone $releases, 'description');
        $withTracks = (clone $releases)->whereIn('id', self::releasedBlank());

        $query->where(fn(Builder $missing) => $missing
            ->where(fn(Builder $q) => self::blank($q, 'projects.description'))
            ->orWhereIn('projects.id', self::blank($tracks, 'description'))
            ->orWhereIn('projects.id', $undescribed)
            ->orWhereIn('projects.id', $withTracks));
    }

    /**
     * Whether any of an existing project is without a description.
     */
    public static function missing(Project $project): bool
    {
        return Project::query()
            ->whereKey($project->id)
            ->tap(fn(Builder $query) => self::whereMissing($query))
            ->exists();
    }

    /**
     * Limit a release query to releases missing a description, on themselves
     * or one of their tracks.
     */
    public static function whereReleaseMissing(Builder $query): void
    {
        $query->where(fn(Builder $missing) => $missing
            ->where(fn(Builder $q) => self::blank($q, 'releases.description'))
            ->orWhereIn('releases.id', self::releasedBlank()));
    }

    /**
     * Ids of the releases with a track missing its description.
     */
    protected static function releasedBlank(): Builder
    {
        $tracks = Track::query()
            ->withoutGlobalScopes()
            ->select('release_id');

        return self::blank($tracks, 'description');
    }

    /**
     * Whether a release or one of its tracks is without a description.
     */
    public static function releaseMissing(Release $release): bool
    {
        return Release::query()
            ->withoutGlobalScopes()
            ->whereKey($release->id)
            ->tap(fn(Builder $query) => self::whereReleaseMissing($query))
            ->exists();
    }

    /**
     * @template TQuery of \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected static function blank($query, string $column)
    {
        return $query->where(fn($q) => $q
            ->whereNull($column)
            ->orWhere($column, ''));
    }

    protected static function empty(?string $value): bool
    {
        return trim((string) $value) === '';
    }
}
