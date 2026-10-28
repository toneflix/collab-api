<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\Project;
use App\Services\Agent\AgentArtwork;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Tasks\GenerateArtwork;

/**
 * Draws a cover for an album or a release, or a picture for an artist the run
 * made, with an artwork AI job. Artwork that can't be drawn leaves the
 * work without it rather than failing the run.
 */
class DrawArtwork implements AgentAction
{
    /** Jobs one picture gets before it's skipped, each with a gentler prompt */
    public const ATTEMPTS = 3;

    public function __construct(
        protected AiStudio $studio,
        protected AgentArtwork $artwork,
    ) {}

    public function advance(AgentStep $step): bool
    {
        if (array_key_exists('file', $step->output ?? [])) {
            return true;
        }

        $job = AiJob::query()->find($step->output['ai_job_id'] ?? null);

        if (! $job) {
            return $this->start($step);
        }

        if ($job->status === AiJob::FAILED) {
            if ((int) ($step->output['attempts'] ?? 1) < self::ATTEMPTS) {
                $step->remember(['ai_job_id' => null]);

                return $this->start($step);
            }

            $step->remember(['file' => null, 'missed' => $job->failure ?: $job->error ?: 'The artwork couldn\'t be drawn.']);

            return true;
        }

        if ($job->status !== AiJob::SUCCEEDED) {
            return false;
        }

        $file = $job->output['images'][0]['file'] ?? null;

        $step->run->increment('credits', $job->credits);

        if ($file) {
            $this->place($step, $file);
        }

        $step->remember(['file' => $file]);

        return true;
    }

    /**
     * Start the AI job, or wait while the owner already has as many running
     * as one account may.
     */
    protected function start(AgentStep $step): bool
    {
        $owner = $step->run->owner;

        $unfinished = AiJob::query()
            ->where('user_id', $owner->id)
            ->unfinished()
            ->count();

        if ($unfinished >= AiStudio::MAX_UNFINISHED) {
            return false;
        }

        $input = $step->input;
        $model = GenerateArtwork::model();
        $attempt = (int) ($step->output['attempts'] ?? 0) + 1;

        $job = $this->studio->start($owner, null, 'artwork', array_filter([
            'artwork_kind' => $input['artwork_kind'],
            'prompt' => mb_substr($this->prompt($input, $attempt), 0, 1000),
            'title' => $input['title'] ?? null,
            'artist' => $input['artist'] ?? null,
            'genre' => $input['genre'] ?? null,
            'count' => 1,
            'artwork_engine' => $model->engine()->value,
            'artwork_model' => $model->model(),
        ], fn($value) => $value !== null && $value !== ''));

        $step->remember(['ai_job_id' => $job->id, 'attempts' => $attempt]);

        return false;
    }

    /**
     * The picture's prompt, gentler on each new attempt: image models return
     * nothing for prompts their safety filter blocks, which dark themes trip.
     *
     * @param  array<string, mixed>  $input
     */
    protected function prompt(array $input, int $attempt): string
    {
        $prompt = trim((string) $input['prompt']);
        $title = trim((string) ($input['title'] ?? ''));

        return match (true) {
            $attempt === 1 => $prompt,
            $attempt === 2 => "Abstract, symbolic artwork in soft colours with no people, loosely inspired by: {$prompt}",
            default => 'Abstract artwork of shapes and colour' . ($title !== '' ? " for \"{$title}\"" : ''),
        };
    }

    /**
     * Put the image on the project, or on the artist when the run made the
     * artist and it has no picture yet. A fill target puts it on whatever of
     * an existing project has no picture. A cover for an existing project's
     * release waits for its release step.
     */
    protected function place(AgentStep $step, string $file): void
    {
        $target = (string) ($step->input['target'] ?? '');

        if ($target === '') {
            return;
        }

        if (str_starts_with($target, 'fill:')) {
            $project = Project::query()->findOrFail((int) substr($target, 5));

            $this->artwork->fill($project, $file);

            return;
        }

        if (str_starts_with($target, 'project:')) {
            $project = Project::query()->findOrFail($step->from($target, 'project_id'));

            $this->artwork->apply($project, $file);

            return;
        }

        $artist = Artist::query()->findOrFail($step->from($target, 'artist_id'));
        $existing = filter_var($step->from($target, 'existing'), FILTER_VALIDATE_BOOLEAN);

        if (! $existing && ! $artist->image) {
            $this->artwork->apply($artist, $file);
        }
    }
}
