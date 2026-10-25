<?php

namespace App\Jobs;

use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Ai\AiSettings;
use App\Services\Audio\AudioTagger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Detects the tempo and key of a track's audio or a project track's preview
 * mix. A released track takes its project track's tags when it has them,
 * unless it brought its own audio.
 */
class TagAudio implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public Track|ProjectTrack $owner,
        public bool $inherit = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->owner::class . ':' . $this->owner->getKey();
    }

    public function handle(AudioTagger $tagger): void
    {
        if (! AiSettings::autoTags()) {
            return;
        }

        if ($this->inherit && $this->owner instanceof Track && $tagger->inherit($this->owner)) {
            return;
        }

        $tagger->detect($this->owner->refresh());
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Tempo and key detection failed', [
            'owner' => $this->uniqueId(),
            'error' => $e?->getMessage(),
            'exception' => $e,
        ]);
    }
}
