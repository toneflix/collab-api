<?php

namespace App\Services\Ai\Music;

use App\Services\Ai\Replicate\ReplicateClient;
use Laravel\Ai\Audio;

/**
 * A music model on Replicate, run through the Laravel AI SDK's `replicate`
 * driver. It makes one song per job.
 */
abstract class ReplicateMusic extends MusicModel
{
    public function generate(array $input, ?string $reference = null): array
    {
        $url = $reference ? $this->upload($reference) : null;

        $audio = Audio::of((string) $input['prompt'])
            ->withProviderOptions($this->input($input, $url))
            ->timeout((int) config('ai.providers.replicate.timeout', 600))
            ->generate('replicate', static::ID);

        $path = tempnam(sys_get_temp_dir(), 'ai-music-');
        file_put_contents($path, $audio->content());

        return [new Song($path, $this->extension($audio->mimeType()), meta: ['model' => $audio->meta->model])];
    }

    /**
     * Upload reference audio so the model can fetch it.
     */
    protected function upload(string $path): string
    {
        return ReplicateClient::make()
            ->upload($path, mime_content_type($path) ?: 'audio/mpeg');
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
