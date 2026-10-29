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
use App\Services\Ai\Music\MusicModel;
use App\Services\Composer\SongProjects;

/**
 * Generates a track's music with an AI job: a song's lyrics are sung with
 * the Composer music model in its artist's voice, instrumentals use the
 * studio's. When the model makes several versions, the first goes on the
 * track.
 */
class MakeMusic implements AgentAction
{
    public function __construct(
        protected AiStudio $studio,
        protected SongProjects $songs,
    ) {}

    public function advance(AgentStep $step): bool
    {
        $job = AiJob::query()->find($step->output['ai_job_id'] ?? null);

        if (! $job) {
            return $this->start($step);
        }

        if ($job->status === AiJob::FAILED) {
            throw new AgentStepFailed('Music: the AI couldn\'t make this track. Its credits were refunded.', $job->failure ?: $job->error);
        }

        if ($job->status !== AiJob::SUCCEEDED) {
            return false;
        }

        $output = $job->output ?? [];

        if (! empty($output['stems'])) {
            $step->run->increment('credits', $job->credits);

            return true;
        }

        if (isset($output['versions']) && ! isset($output['choosing'])) {
            if (! empty($step->output['chose'])) {
                throw new AgentStepFailed('Music: the version couldn\'t be added to the track.', $job->failure);
            }

            $this->studio->choose($job, 0);
            $step->remember(['chose' => true]);
        }

        return false;
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

        [$kind] = explode(':', (string) $input['source']);

        if ($kind === 'song') {
            $song = Song::query()->findOrFail($step->from($input['source'], 'song_id'));
            $model = AiSettings::composerMusicModel();
            $artist = isset($input['artist']) ? Artist::query()->find($step->from($input['artist'], 'artist_id')) : null;
            $voice = $artist?->voice;

            $job = $this->songs->generate($song, $owner, array_filter([
                'prompt' => $this->prompt($model, $input, $voice?->description),
                'seconds' => (int) $input['seconds'],
                'voice_artist_id' => $voice && $model->voiceFrom($voice) ? $artist->id : null,
                'vocal_gender' => $voice?->gender(),
            ]));
        } else {
            $track = ProjectTrack::query()->findOrFail($step->from($input['source'], 'project_track_id'));
            $model = AiSettings::musicModel();

            $job = $this->studio->start($owner, $track, 'music', array_filter([
                'model' => $model::ID,
                'prompt' => $this->prompt($model, $input),
                'seconds' => $model->maxSeconds()
                    ? min(max((int) $input['seconds'], $model->minSeconds()), $model->maxSeconds(), AiSettings::musicMaxSeconds())
                    : null,
                'instrumental' => $model->instrumentalOption() ? true : $model->instrumentalOnly(),
                'split' => false,
                ...$model->jobSettings(),
            ], fn($value) => $value !== null));
        }

        $step->remember(['ai_job_id' => $job->id]);

        return false;
    }

    /**
     * The track's style, led by its singer's voice when it has one, cut to
     * the model's prompt length.
     *
     * @param  array<string, mixed>  $input
     */
    protected function prompt(MusicModel $model, array $input, ?string $voice = null): string
    {
        [$min, $max] = $model->promptLength();
        $style = trim((string) $input['style']) ?: (string) $input['title'];
        $prompt = filled($voice) ? "Vocals: {$voice}. {$style}" : $style;

        if (mb_strlen($prompt) < $min) {
            $prompt = trim($prompt . ', ' . $input['title']);
        }

        return mb_substr($prompt, 0, $max);
    }
}
