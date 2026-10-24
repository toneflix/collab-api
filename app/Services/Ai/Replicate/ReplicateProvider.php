<?php

namespace App\Services\Ai\Replicate;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Providers\Concerns\GeneratesAudio;
use Laravel\Ai\Providers\Concerns\HasAudioGateway;
use Laravel\Ai\Providers\Provider;

/**
 * Replicate as a Laravel AI audio provider, so music is generated with
 * Audio::of($prompt)->generate('replicate').
 */
class ReplicateProvider extends Provider implements AudioProvider
{
    use GeneratesAudio;
    use HasAudioGateway;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new ReplicateAudioGateway;
    }

    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'elevenlabs/music';
    }

    public function client(): ReplicateClient
    {
        return new ReplicateClient($this->config);
    }
}
