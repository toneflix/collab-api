<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
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
        $this->authorizeTrack($request, $project, $track);

        $valid = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'seconds' => ['required', 'integer', 'min:3', 'max:'.AiSettings::musicMaxSeconds()],
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
        $this->authorizeTrack($request, $project, $track);
        abort_unless((int) $stem->track_id === (int) $track->id, HttpStatus::NOT_FOUND->value, 'Stem not found.');

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
        $this->authorizeTrack($request, $project, $track);

        $jobs = AiJob::query()->where('project_track_id', $track->id)->latest('id')->limit(20)->get();

        return Providers::response()->success([
            'data' => $jobs->map(fn (AiJob $job) => $job->present())->all(),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function show(Request $request, AiJob $job)
    {
        abort_unless((int) $job->user_id === (int) $request->user()->id, HttpStatus::NOT_FOUND->value, 'Job not found.');

        return Providers::response()->success([
            'data' => $job->present(),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    protected function authorizeTrack(Request $request, Project $project, ProjectTrack $track): void
    {
        abort_unless(
            (int) $track->project_id === (int) $project->id && $project->canEdit($request->user()),
            HttpStatus::FORBIDDEN->value,
            'You can\'t work on this track.'
        );
    }

    protected function started(AiJob $job, string $message)
    {
        return Providers::response()->success([
            'data' => $job->present(),
            'message' => $message,
        ], HttpStatus::CREATED);
    }
}
