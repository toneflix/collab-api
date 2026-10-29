<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\AiJob;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiStudio;
use App\Services\Lyrics\Lyrics;

/**
 * Times a track's lyric sheet to its sung audio with a lyrics AI job in sync
 * mode, so players can follow the words. A track whose sheet can't be timed
 * keeps it untimed rather than failing the run.
 */
class SyncLyrics implements AgentAction
{
    public function __construct(
        protected AiStudio $studio,
        protected Lyrics $lyrics,
    ) {}

    public function advance(AgentStep $step): bool
    {
        if (array_key_exists('synced', $step->output ?? [])) {
            return true;
        }

        $job = AiJob::query()->find($step->output['ai_job_id'] ?? null);

        if (! $job) {
            return $this->start($step);
        }

        if ($job->status === AiJob::FAILED) {
            $step->remember(['synced' => false, 'missed' => 'The lyrics couldn\'t be timed.', 'failure' => $job->failure ?: $job->error]);

            return true;
        }

        if ($job->status !== AiJob::SUCCEEDED) {
            return false;
        }

        $step->run->increment('credits', $job->credits);
        $step->remember(['synced' => (int) ($job->output['lines'] ?? 0) > 0, 'missed' => $job->output['notice'] ?? null]);

        return true;
    }

    /**
     * Start the AI job on the track's sung stem, or wait while the owner
     * already has as many running as one account may.
     */
    protected function start(AgentStep $step): bool
    {
        $owner = $step->run->owner;
        $input = $step->input;
        $trackId = $input['track_id'] ?? $step->from($input['source'], 'project_track_id');
        $track = ProjectTrack::query()->findOrFail($trackId);
        $stem = $this->stem($step, $track);

        if (! $stem || ! $this->lyrics->for($track)) {
            $step->remember(['synced' => false, 'missed' => $stem ? 'The track has no lyric sheet.' : 'The track has no audio to time the lyrics to.']);

            return true;
        }

        $unfinished = AiJob::query()
            ->where('user_id', $owner->id)
            ->unfinished()
            ->count();

        if ($unfinished >= AiStudio::MAX_UNFINISHED) {
            return $step->waitForSlot();
        }

        $job = $this->studio->start($owner, $track, 'lyrics', [
            'stem_id' => $stem->id,
            'title' => (string) $stem->title,
            'mode' => 'sync',
        ]);

        $step->remember(['ai_job_id' => $job->id]);

        return false;
    }

    /**
     * The stem the music step made, else the track's vocal stem, else its
     * latest.
     */
    protected function stem(AgentStep $step, ProjectTrack $track): ?ProjectTrackStem
    {
        $music = $step->input['music'] ?? null;
        $jobId = $music ? $step->from($music, 'ai_job_id') : null;
        $made = $jobId ? (AiJob::query()->find($jobId)?->output['stems'][0] ?? null) : null;

        if ($made) {
            return $track
                ->stems()
                ->whereKey($made)
                ->first();
        }

        $vocal = $track
            ->stems()
            ->whereRaw('lower(title) like ?', ['%vocal%'])
            ->latest('id')
            ->first();

        return $vocal ?? $track
            ->stems()
            ->latest('id')
            ->first();
    }
}
