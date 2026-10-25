<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiJobCollection;
use App\Http\Resources\AiJobResource;
use App\Models\AiJob;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use Illuminate\Http\Request;

/**
 * AI tools in the studio: generate music for a track and split stems.
 */
class AiController extends Controller
{
    public function music(Request $request, AiStudio $studio, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $valid = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'seconds' => ['required', 'integer', 'min:3', 'max:' . AiSettings::musicMaxSeconds()],
            'instrumental' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'split' => ['nullable', 'boolean'],
        ]);

        $job = $studio->start($request->user(), $track, 'music', [
            'prompt' => trim($valid['prompt']),
            'seconds' => (int) $valid['seconds'],
            'instrumental' => (bool) ($valid['instrumental'] ?? false),
            'seed' => $valid['seed'] ?? null,
            'split' => (bool) ($valid['split'] ?? false),
        ]);

        return $this->started($job, 'Generating your music. It shows up on the track when it\'s ready.');
    }

    public function split(Request $request, AiStudio $studio, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $this->authorize('update', $track);

        $job = $studio->start($request->user(), $track, 'stems', [
            'stem_id' => $stem->id,
            'title' => (string) $stem->title,
        ]);

        return $this->started($job, 'Splitting into stems. They show up on the track when they\'re ready.');
    }

    /**
     * The track's latest AI jobs.
     */
    public function jobs(Request $request, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $request->validate(['limit' => ['nullable', 'integer', 'between:1,50']]);

        $jobs = AiJob::query()
            ->where('project_track_id', $track->id)
            ->latest('id')
            ->paginate($request->perPage(20, 50));

        return (new AiJobCollection($jobs))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    public function show(Request $request, AiJob $job)
    {
        abort_unless((int) $job->user_id === (int) $request->user()->id, HttpStatus::NOT_FOUND->value, 'Job not found.');

        return (new AiJobResource($job))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    protected function started(AiJob $job, string $message)
    {
        return (new AiJobResource($job))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }
}
