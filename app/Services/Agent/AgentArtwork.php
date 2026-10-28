<?php

namespace App\Services\Agent;

use App\Models\Artist;
use App\Models\Project;
use App\Models\Release;
use App\Models\Song;
use App\Services\Ai\Artwork\ArtworkImages;
use App\Services\Composer\SongArtwork;
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
     * Whether any of an existing project is without a picture.
     */
    public static function missing(Project $project): bool
    {
        if (! $project->image) {
            return true;
        }

        $releases = $project
            ->releases()
            ->withoutGlobalScopes()
            ->whereNull('image')
            ->exists();

        return $releases || Song::query()
            ->whereIn('project_track_id', $project->tracks()->select('id'))
            ->whereNull('image')
            ->exists();
    }
}
