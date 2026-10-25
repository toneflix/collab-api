<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiJobResource;
use App\Http\Resources\LyricResource;
use App\Models\Lyric;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\Track;
use App\Services\Ai\AiStudio;
use App\Services\Lyrics\Lyrics;
use Illuminate\Http\Request;

/**
 * The lyric sheet of a project track in the studio, and the lyrics of a
 * creator's released tracks.
 */
class LyricsController extends Controller
{
    public function show(Lyrics $lyrics, Project $project, ProjectTrack $track)
    {
        $this->authorize('view', $track);

        $lyric = $lyrics->for($track);

        return $this->lyrics($lyric);
    }

    public function update(Request $request, Lyrics $lyrics, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $lines = $this->lines($request);
        $lyric = $lyrics->save($track, $lines, Lyric::WRITTEN, $request->user());

        return $this->lyrics($lyric, 'Lyrics saved.', HttpStatus::ACCEPTED);
    }

    /**
     * Start writing out the lyrics sung on a stem, as an AI job.
     */
    public function transcribe(Request $request, AiStudio $studio, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $this->authorize('update', $track);

        $job = $studio->start($request->user(), $track, 'lyrics', [
            'stem_id' => $stem->id,
            'title' => (string) $stem->title,
        ]);

        return (new AiJobResource($job))
            ->additional([
                'message' => 'Writing out the lyrics. They show up on the lyric sheet when they\'re ready.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Correct the lyrics of a released track.
     */
    public function updateTrack(Request $request, Lyrics $lyrics, Track $track)
    {
        abort_unless($track->release?->isOwnedBy($request->user()), HttpStatus::NOT_FOUND->value, 'Track not found.');

        $lines = $this->lines($request);
        $lyric = $lyrics->save($track, $lines, Lyric::WRITTEN, $request->user());

        return $this->lyrics($lyric, 'Lyrics saved.', HttpStatus::ACCEPTED);
    }

    /**
     * @return array<int, mixed>
     */
    protected function lines(Request $request): array
    {
        $request->validate([
            'lines' => ['present', 'array', 'max:' . Lyrics::MAX_LINES],
            'lines.*.text' => ['nullable', 'string', 'max:' . Lyrics::MAX_LINE_LENGTH],
            'lines.*.start' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'lines.*.end' => ['nullable', 'numeric', 'min:0', 'max:86400'],
        ]);

        return (array) $request->input('lines', []);
    }

    protected function lyrics(?Lyric $lyric, ?string $message = null, HttpStatus $status = HttpStatus::OK)
    {
        return (new LyricResource($lyric))
            ->additional([
                'message' => $message ?? HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
