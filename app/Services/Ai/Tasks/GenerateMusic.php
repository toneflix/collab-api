<?php

namespace App\Services\Ai\Tasks;

use App\Models\AiJob;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiSettings;
use App\Services\Studio\StemFiles;
use Laravel\Ai\Audio;

/**
 * Generates a track from a prompt with ElevenLabs Music, then adds it to the
 * project track as one stem, or splits it into stems first.
 */
class GenerateMusic implements AiTask
{
    public function __construct(protected StemFiles $files, protected SplitStems $splitter) {}

    public function credits(array $input): int
    {
        return AiSettings::musicCredits((int) $input['seconds'])
            + (! empty($input['split']) ? AiSettings::stemsCredits() : 0);
    }

    public function describe(array $input): string
    {
        return 'Music: '.str((string) $input['prompt'])->limit(80);
    }

    public function handle(AiJob $job): array
    {
        $input = $job->input;

        $audio = Audio::of((string) $input['prompt'])
            ->withProviderOptions(array_filter([
                'music_length_ms' => (int) $input['seconds'] * 1000,
                'force_instrumental' => (bool) ($input['instrumental'] ?? false),
                'seed' => $input['seed'] ?? null,
            ], fn ($value) => $value !== null))
            ->timeout((int) config('ai.providers.replicate.timeout', 600))
            ->generate('replicate');

        $path = tempnam(sys_get_temp_dir(), 'ai-music-');
        file_put_contents($path, $audio->content());

        $title = str((string) $input['prompt'])->limit(60, '')->toString();
        $meta = [
            'duration' => (float) $input['seconds'],
            'ai' => array_filter([
                'prompt' => (string) $input['prompt'],
                'seed' => $input['seed'] ?? null,
                'model' => $audio->meta->model,
                'job' => $job->id,
            ], fn ($value) => $value !== null),
        ];

        try {
            $stems = ! empty($input['split'])
                ? $this->splitter->separate($job->track, $job->user, $path, $title, $meta)
                : [$this->files->addStem($job->track, $job->user, $path, $title, $meta, $this->extension($audio->mimeType()))];
        } finally {
            @unlink($path);
        }

        return ['stems' => array_map(fn (ProjectTrackStem $stem) => $stem->id, $stems)];
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
