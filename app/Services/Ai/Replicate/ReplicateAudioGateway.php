<?php

namespace App\Services\Ai\Replicate;

use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Generates audio by running a Replicate model with the text as its prompt.
 * Provider options are passed to the model as inputs.
 */
class ReplicateAudioGateway implements AudioGateway
{
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        /** @var ReplicateProvider $provider */
        $client = $provider->client();

        $prediction = $client->run($model, ['prompt' => $text, ...$providerOptions], $timeout);

        $url = collect((array) $prediction['output'])->flatten()->first(fn ($value) => is_string($value) && str_starts_with($value, 'http'));

        if (! $url) {
            throw new ReplicateException("{$model} returned no audio.");
        }

        $path = $client->download($url);

        try {
            $mime = mime_content_type($path) ?: 'audio/mpeg';

            return new AudioResponse(
                base64_encode((string) file_get_contents($path)),
                new Usage,
                new Meta($provider->name(), $model),
                $mime === 'application/octet-stream' ? 'audio/mpeg' : $mime,
            );
        } finally {
            @unlink($path);
        }
    }
}
