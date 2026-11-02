<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\ProjectTrack;
use App\Models\Song;
use App\Services\Agent\AgentStepFailed;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;

/**
 * Sings a song's lyrics over its beat, written as notes and mixed by the
 * steps before, in its artist's voice. The vocals go on the track as their
 * own stem.
 */
class SingVocals implements AgentAction
{
    public function __construct(protected AiStudio $studio) {}

    public function advance(AgentStep $step): bool
    {
        $job = AiJob::query()->find($step->output['ai_job_id'] ?? null);

        if (! $job) {
            return $this->start($step);
        }

        if ($job->status === AiJob::FAILED) {
            throw new AgentStepFailed('Vocals: the AI couldn\'t sing over this track. Its credits were refunded.', $job->failure ?: $job->error);
        }

        if ($job->status !== AiJob::SUCCEEDED) {
            return false;
        }

        $step->run->increment('credits', $job->credits);

        return true;
    }

    /**
     * Start the AI job, or wait while the owner already has as many running
     * as one account may.
     */
    protected function start(AgentStep $step): bool
    {
        $owner = $step->run->owner;
        $input = $step->input;

        $unfinished = AiJob::query()
            ->where('user_id', $owner->id)
            ->unfinished()
            ->count();

        if ($unfinished >= AiStudio::MAX_UNFINISHED) {
            return $step->waitForSlot();
        }

        $song = Song::query()->findOrFail($step->from($input['source'], 'song_id'));
        $track = ProjectTrack::query()->findOrFail($step->from($input['source'], 'project_track_id'));
        $artist = isset($input['artist']) ? Artist::query()->find($step->from($input['artist'], 'artist_id')) : null;
        $voice = $artist?->voice;
        $style = trim((string) $input['style']) ?: (string) $input['title'];

        $job = $this->studio->start($owner, $track, 'vocals', array_filter([
            'title' => (string) $input['title'],
            'prompt' => filled($voice?->description) ? "Vocals: {$voice->description}. {$style}" : $style,
            'lyrics' => trim((string) $song->body),
            'vocal_gender' => $voice?->gender(),
            'suno_version' => AiSettings::sunoVersion(),
        ]));

        $step->remember(['ai_job_id' => $job->id]);

        return false;
    }
}
