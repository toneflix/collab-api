<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Song;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Composer\SongArtwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Toneflix\LaravelFileable\Media;

/**
 * Puts an image an agent artwork job drew on what the agent made: a
 * project, release or song cover, or an artist's picture, and fills in the
 * pictures an existing project is missing.
 */
class AgentArtwork
{
    /** The fileable collection each model keeps its picture in */
    protected const COLLECTIONS = [
        Project::class => 'artwork',
        Release::class => 'artwork',
        Song::class => SongArtwork::COLLECTION,
        Artist::class => 'avatar',
    ];

    public function __construct(protected ArtworkImages $images) {}

    /**
     * Save the image as the model's picture. False when the image is gone.
     */
    public function apply(Model $model, string $file): bool
    {
        $upload = $this->images->upload($file);

        if (! $upload) {
            return false;
        }

        try {
            $media = new Media($model->disk);

            $model->image = $media->save(self::COLLECTIONS[$model::class], $upload, $model->image);
            $model->saveQuietly();
        } finally {
            @unlink($upload->getPathname());
        }

        return true;
    }

    /**
     * Put the image on whatever of an existing project has no picture: the
     * project, its releases and the songs on its tracks. Released tracks
     * show their release's or project's picture.
     */
    public function fill(Project $project, string $file): void
    {
        if (! $project->image) {
            $this->apply($project, $file);
        }

        $releases = $project
            ->releases()
            ->withoutGlobalScopes()
            ->whereNull('image')
            ->get();

        foreach ($releases as $release) {
            $this->apply($release, $file);
        }

        Song::query()
            ->whereIn('project_track_id', $project->tracks()->select('id'))
            ->whereNull('image')
            ->lazyById(50)
            ->each(fn(Song $song) => $this->apply($song, $file));
    }

    /**
     * Put the image on a release with no project when it has no picture.
     */
    public function fillRelease(Release $release, string $file): void
    {
        if (! $release->image) {
            $this->apply($release, $file);
        }
    }

    /**
     * Limit a project query to projects with anything missing a picture: the
     * project, one of its releases or a song on one of its tracks.
     */
    public static function whereMissing(Builder $query): void
    {
        $query->where(fn(Builder $missing) => $missing
            ->whereNull('projects.image')
            ->orWhereIn(
                'projects.id',
                Release::query()
                    ->withoutGlobalScopes()
                    ->select('project_id')
                    ->whereNotNull('project_id')
                    ->whereNull('image'),
            )
            ->orWhereIn(
                'projects.id',
                ProjectTrack::query()
                    ->select('project_id')
                    ->whereHas('song', fn(Builder $song) => $song->whereNull('image')),
            ));
    }

    /**
     * Whether any of an existing project is without a picture.
     */
    public static function missing(Project $project): bool
    {
        return Project::query()
            ->whereKey($project->id)
            ->tap(fn(Builder $query) => self::whereMissing($query))
            ->exists();
    }
}
