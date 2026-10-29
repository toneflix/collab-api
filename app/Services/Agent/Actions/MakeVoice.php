<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\AiJob;
use App\Models\Artist;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Artists\ArtistVoices;

/**
 * Gives an artist the run sings with a short AI voice sample, so its tracks
 * keep one voice. An artist that already has a sample keeps it, and a sample
 * that can't be made leaves the tracks to the voice description.
 */
class MakeVoice implements AgentAction
{
    public function __construct(protected ArtistVoices $voices) {}

    public function advance(AgentStep $step): bool
    {
        if (array_key_exists('sample', $step->output ?? [])) {
            return true;
        }

        $artist = Artist::query()->findOrFail($step->from($step->input['artist'], 'artist_id'));
        $job = AiJob::query()->find($step->output['ai_job_id'] ?? null);

        if (! $job) {
            return $this->start($step, $artist);
        }

        if ($job->status === AiJob::FAILED) {
            $step->remember(['sample' => false, 'missed' => 'The voice sample couldn\'t be made.', 'failure' => $job->failure ?: $job->error]);

            return true;
        }

        if ($job->status !== AiJob::SUCCEEDED) {
            return false;
        }

        $step->run->increment('credits', $job->credits);
        $step->remember(['sample' => true]);

        return true;
    }

    /**
     * Start the AI job, or wait while the owner already has as many running
     * as one account may.
     */
    protected function start(AgentStep $step, Artist $artist): bool
    {
        $voice = $this->voices->for($artist);
        $model = AiSettings::composerMusicModel();

        if ($voice->hasSample() || ! $model->takesVoices()) {
            $step->remember(['sample' => $voice->hasSample(), 'existing' => $voice->hasSample()]);

            return true;
        }

        $unfinished = AiJob::query()
            ->where('user_id', $step->run->owner_id)
            ->unfinished()
            ->count();

        if ($unfinished >= AiStudio::MAX_UNFINISHED) {
            return false;
        }

        $job = $this->voices->generate($artist, $step->run->owner);

        $step->remember(['ai_job_id' => $job->id]);

        return false;
    }
}
