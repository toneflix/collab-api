<?php

namespace App\Services\Ai\Tasks;

use App\Models\AiJob;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Music\ElevenLabsMusic;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\ReferenceAudio;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;
use Laravel\Ai\Audio;
use Throwable;

/**
 * Generates a track with the music model admins chose, then adds it to the
 * project track as one stem, or splits it into stems first. When the split
 * fails the song is still added whole, and the split's credits come back.
 * Lossless output is split as it is and encoded once for the stem.
 */
class GenerateMusic implements AiTask
{
    public function __construct(
        protected StemFiles $files,
        protected SplitStems $splitter,
        protected AiStudio $studio,
        protected ReferenceAudio $references,
        protected StemAudio $audio,
    ) {}

    public function credits(array $input): int
    {
        return $this->model($input)->credits((int) ($input['seconds'] ?? 0))
            + (! empty($input['split']) ? AiSettings::stemsCredits() : 0);
    }

    public function describe(array $input): string
    {
        return 'Music: '.str((string) $input['prompt'])->limit(80);
    }

    public function handle(AiJob $job): array
    {
        $input = $job->input;
        $model = $this->model($input);

        try {
            $audio = Audio::of((string) $input['prompt'])
                ->withProviderOptions($model->input($input, $this->reference($job)))
                ->timeout((int) config('ai.providers.replicate.timeout', 600))
                ->generate('replicate', $model::ID);
        } finally {
            $this->references->forget($input);
        }

        $ext = $this->extension($audio->mimeType());
        $path = tempnam(sys_get_temp_dir(), 'ai-music-');
        file_put_contents($path, $audio->content());
        $temp = [$path];

        $title = str((string) $input['prompt'])->limit(60, '')->toString();
        $meta = [
            'duration' => (float) ($input['seconds'] ?? 0) ?: round($this->audio->probe($path)['duration'], 3),
            'ai' => array_filter([
                'prompt' => (string) $input['prompt'],
                'seed' => $input['seed'] ?? null,
                'model' => $audio->meta->model,
                'job' => $job->id,
            ], fn ($value) => $value !== null),
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
            array_map(fn (string $file) => @unlink($file), $temp);
        }

        return array_filter([
            'stems' => array_map(fn (ProjectTrackStem $stem) => $stem->id, $stems),
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
     * Upload the job's reference audio so the model can fetch it.
     */
    protected function reference(AiJob $job): ?string
    {
        $path = $this->references->local($job->input, $job->track);

        if (! $path) {
            return null;
        }

        try {
            $client = ReplicateClient::make();

            return $client->upload($path, mime_content_type($path) ?: 'audio/mpeg');
        } finally {
            @unlink($path);
        }
    }

    /**
     * Split the song into stems, or give null when that fails.
     *
     * @param  array<string, mixed>  $meta
     * @return list<ProjectTrackStem>|null
     */
    protected function split(AiJob $job, string $path, string $title, array $meta): ?array
    {
        try {
            return $this->splitter->separate($job->track, $job->user, $path, $title, $meta);
        } catch (Throwable $e) {
            $this->studio->failStep($job, $e, 'Split into stems', AiSettings::stemsCredits());

            return null;
        }
    }

    protected function extension(?string $mime): string
    {
        return match ($mime) {
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg', 'audio/opus' => 'ogg',
            default => 'mp3',
        };
    }
}
