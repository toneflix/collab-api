<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiJobStopped;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Music\ElevenLabsMusic;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\MusicVersions;
use App\Services\Ai\Music\ReferenceAudio;
use App\Services\Ai\Music\Song;
use App\Services\Artists\ArtistVoices;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;
use Throwable;

/**
 * Generates music with the model the job started with. One song goes on the
 * track as a stem, or split into stems first; when the split fails the song
 * is still added whole and the split's credits come back. When the model
 * made several versions, they wait for the owner to pick one.
 */
class GenerateMusic implements AiTask
{
    public function __construct(
        protected StemFiles $files,
        protected SplitStems $splitter,
        protected AiStudio $studio,
        protected ReferenceAudio $references,
        protected StemAudio $audio,
        protected MusicVersions $versions,
        protected ArtistVoices $voices,
    ) {}

    public function credits(array $input): int
    {
        return $this->model($input)->credits((int) ($input['seconds'] ?? 0))
            + (! empty($input['split']) ? AiSettings::stemsCredits($this->splitter->engine($input)) : 0);
    }

    public function describe(array $input): string
    {
        return 'Music: ' . str((string) $input['prompt'])->limit(80);
    }

    public function engine(array $input): AiEngine
    {
        return $this
            ->model($input)
            ->engine();
    }

    public function handle(AiJob $job): array
    {
        $model = $this->model($job->input);
        [$input, $sample] = $this->withVoice($model, $job->input);
        $reference = $sample ?? $this->references->local($input, $job->track);

        try {
            $songs = $model->generate($input, $reference);
        } finally {
            $this->references->forget($input);

            if ($reference) {
                @unlink($reference);
            }
        }

        try {
            return count($songs) > 1
                ? ['versions' => $this->versions->keep($job, $songs)]
                : $this->place($job, $songs[0]);
        } finally {
            array_map(fn(Song $song) => $song->forget(), $songs);
        }
    }

    /**
     * The job's input set to sing in its artist's voice, and a local copy of
     * the voice's sample when the model follows the sample itself.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    protected function withVoice(MusicModel $model, array $input): array
    {
        $voice = $this->voices->forJob($input);
        $input['vocal_gender'] ??= $voice?->gender();

        return match ($voice ? $model->voiceFrom($voice) : null) {
            MusicModel::VOICE_PERSONA => [[...$input, 'persona' => ['id' => $voice->persona_id, 'kind' => $voice->persona_kind]], null],
            MusicModel::VOICE_FILE => empty($input['reference_file']) && empty($input['reference_stem_id'])
                ? [[...$input, 'reference_kind' => 'voice'], $this->voices->local($voice)]
                : [$input, null],
            default => [$input, null],
        };
    }

    /**
     * Put the version the owner picked on the track and drop the others.
     *
     * @return array<string, mixed>
     */
    public function choose(AiJob $job, int $index): array
    {
        $song = $this->versions->take($job, $index);

        try {
            $output = $this->place($job, $song);
        } finally {
            $song->forget();
        }

        $this->versions->forget($job);

        return $output;
    }

    /**
     * Add a song to the job's track, split into stems when asked.
     *
     * @return array{stems: list<int>, notice?: string}
     */
    protected function place(AiJob $job, Song $song): array
    {
        $input = $job->input;
        $path = $song->path;
        $ext = $song->extension;
        $temp = [];

        $title = $song->title ?: str((string) $input['prompt'])
            ->limit(60, '')
            ->toString();
        $meta = [
            'duration' => $song->duration ?: ((float) ($input['seconds'] ?? 0) ?: round($this->audio->probe($path)['duration'], 3)),
            'ai' => array_filter([
                'prompt' => (string) $input['prompt'],
                'seed' => $input['seed'] ?? null,
                'job' => $job->id,
                ...$song->meta,
            ], fn($value) => $value !== null),
        ];

        $notice = null;

        try {
            $stems = ! empty($input['split']) ? $this->split($job, $path, $title, $meta) : null;

            if ($stems === null) {
                if ($ext === 'wav') {
                    $path = $temp[] = $this->audio->mp3($path);
                    $ext = 'mp3';
                }

                $stems = [$this->files->addStem($job->track, $job->user, $path, $title, $meta, $ext)];
                $notice = ! empty($input['split'])
                    ? "The song couldn't be split into stems, so it was added whole. The split's credits were refunded."
                    : null;
            }
        } finally {
            array_map(fn(string $file) => @unlink($file), $temp);
        }

        return array_filter([
            'stems' => array_map(fn(ProjectTrackStem $stem) => $stem->id, $stems),
            'notice' => $notice,
        ]);
    }

    /**
     * The model a job was started with, or the current one for older jobs.
     *
     * @param  array<string, mixed>  $input
     */
    protected function model(array $input): MusicModel
    {
        return MusicModel::find($input['model'] ?? null) ?? MusicModel::find(ElevenLabsMusic::ID);
    }

    /**
     * Split the song into stems, or give null when that fails.
     *
     * @param  array<string, mixed>  $meta
     * @return list<ProjectTrackStem>|null
     */
    protected function split(AiJob $job, string $path, string $title, array $meta): ?array
    {
        $engine = $this->splitter->engine($job->input);

        try {
            return $this->splitter->separate($job->track, $job->user, $path, $title, $meta, $engine);
        } catch (AiJobStopped $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->studio->failStep($job, $e, 'Split into stems', AiSettings::stemsCredits($engine));

            return null;
        }
    }
}
