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
use App\Services\Ai\Music\MusicVersions;
use App\Services\Ai\Music\ReferenceAudio;
use Illuminate\Http\Request;
use Throwable;

/**
 * AI tools in the studio: generate music for a track, pick a version of it
 * and split stems.
 */
class AiController extends Controller
{
    public function music(Request $request, AiStudio $studio, ReferenceAudio $references, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $model = AiSettings::musicModel();

        $valid = $request->validate([
            ...$model->rules(),
            'split' => ['nullable', 'boolean'],
            'reference' => $model->references() ? ['nullable', 'file', 'mimes:mp3,wav', 'max:20480'] : ['prohibited'],
            'reference_stem_id' => $model->references() ? ['nullable', 'integer'] : ['prohibited'],
        ], [
            'reference.prohibited' => $model::NAME . ' doesn\'t take reference audio.',
            'reference_stem_id.prohibited' => $model::NAME . ' doesn\'t take reference audio.',
        ]);

        $reference = $references->keep($model, $track, $request->file('reference'), $valid['reference_stem_id'] ?? null);

        $input = array_filter([
            'model' => $model::ID,
            'prompt' => trim($valid['prompt']),
            'lyrics' => isset($valid['lyrics']) ? trim($valid['lyrics']) : null,
            'seconds' => $model->maxSeconds() ? (int) $valid['seconds'] : null,
            'instrumental' => $model->instrumentalOption() ? (bool) ($valid['instrumental'] ?? false) : $model->instrumentalOnly(),
            'seed' => $valid['seed'] ?? null,
            'split' => (bool) ($valid['split'] ?? false),
            'stems_engine' => ! empty($valid['split']) ? AiSettings::stemsEngine()->value : null,
            'reference_kind' => $reference ? ($valid['reference_kind'] ?? $model->references()[0]) : null,
            ...$reference,
            ...$model->jobSettings(),
        ], fn($value) => $value !== null);

        try {
            $job = $studio->start($request->user(), $track, 'music', $input);
        } catch (Throwable $e) {
            $references->forget($input);

            throw $e;
        }

        return $this->started($job, 'Generating your music.');
    }

    public function split(Request $request, AiStudio $studio, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $this->authorize('update', $track);

        $job = $studio->start($request->user(), $track, 'stems', [
            'stem_id' => $stem->id,
            'title' => (string) $stem->title,
            'stems_engine' => AiSettings::stemsEngine()->value,
        ]);

        return $this->started($job, 'Splitting into stems.');
    }

    /**
     * Put the version of a generated song the owner picked on the track.
     */
    public function choose(Request $request, AiStudio $studio, AiJob $job, int $version)
    {
        abort_unless((int) $job->user_id === (int) $request->user()->id, HttpStatus::NOT_FOUND->value, 'Job not found.');
        $this->authorize('update', $job->track);

        $job = $studio->choose($job, $version);

        return (new AiJobResource($job))
            ->additional([
                'message' => 'Adding that version to the track.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Play a version of a generated song, from a signed link.
     */
    public function version(MusicVersions $versions, AiJob $job, int $version)
    {
        return $versions->stream($job, $version);
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

        return (new AiJobCollection($jobs))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function show(Request $request, AiJob $job)
    {
        abort_unless((int) $job->user_id === (int) $request->user()->id, HttpStatus::NOT_FOUND->value, 'Job not found.');

        return (new AiJobResource($job))
            ->additional([
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
