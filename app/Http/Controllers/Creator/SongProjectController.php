<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiJobResource;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Services\Composer\SongProjects;
use Illuminate\Http\Request;

/**
 * Makes a project from a song, and keeps that project's lyric sheet in step with the song.
 */
class SongProjectController extends Controller
{
    public function store(Request $request, SongProjects $projects, Song $song)
    {
        $this->authorize('update', $song);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'privacy' => ['nullable', 'string', 'in:private,public,public_preview'],
            'genre_id' => ['required', 'integer', 'exists:genres,id'],
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'generate' => ['nullable', 'boolean'],
            'prompt' => ['nullable', 'required_if_accepted:generate', 'string', 'max:2000'],
            'seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $made = $projects->create($song, $this->user, $data);
        $job = $made['job'];
        $song->load(['beat', 'track.project']);

        return (new SongResource($song))
            ->additional([
                'aiJob' => $job ? new AiJobResource($job) : null,
                'message' => $job
                    ? 'Project created. AI is making the song, and it shows up on the track when it\'s ready.'
                    : 'Project created with the lyric sheet.',
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
