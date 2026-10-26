<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiJobResource;
use App\Http\Resources\PublicSongResource;
use App\Http\Resources\SongResource;
use App\Models\Project;
use App\Models\Song;
use App\Services\Composer\SongProjects;
use Illuminate\Http\Request;

/**
 * Puts a song on a new project or an existing one, and keeps that project's lyric sheet in step with the song.
 * Others can start a project with a published song when its writer allows it.
 */
class SongProjectController extends Controller
{
    public function store(Request $request, SongProjects $projects, Song $song)
    {
        $this->authorize('project', $song);

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'title' => ['nullable', 'string', 'max:150'],
            'privacy' => ['nullable', 'string', 'in:private,public,public_preview'],
            'genre_id' => ['required', 'integer', 'exists:genres,id'],
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'generate' => ['nullable', 'boolean'],
            'prompt' => ['nullable', 'required_if_accepted:generate', 'string', 'max:2000'],
            'seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        if ($project) {
            $this->authorize('update', $project);
        }

        $made = $projects->create($song, $this->user, $data, $project);
        $job = $made['job'];
        $track = $made['track'];
        $done = $project ? "Track added to {$project->title}" : 'Project created';

        $track->load('project');

        if ($song->user_id === $this->user->id) {
            $song->load(['beat', 'track.project', 'forkedFrom']);
            $resource = new SongResource($song);
        } else {
            $song->load('user');
            $resource = new PublicSongResource($song);
        }

        return $resource
            ->additional([
                'aiJob' => $job ? new AiJobResource($job) : null,
                'project' => [
                    'id' => $track->project->id,
                    'slug' => $track->project->slug,
                    'title' => $track->project->title,
                    'trackId' => $track->id,
                ],
                'message' => $job
                    ? "{$done}. AI is making the song, and it shows up on the track when it's ready."
                    : "{$done} with the lyric sheet.",
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Puts the song's current lyrics on its project track's lyric sheet.
     */
    public function lyrics(SongProjects $projects, Song $song)
    {
        $this->authorize('update', $song);

        $song->loadMissing('track');

        if ($song->track) {
            $this->authorize('update', $song->track);
        }

        $projects->syncLyrics($song, $this->user);
        $song->load(['beat', 'track.project']);

        return (new SongResource($song))
            ->additional([
                'message' => 'Lyric sheet updated.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
