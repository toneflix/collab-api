<?php

namespace App\Jobs;

use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Ai\AiSettings;
use App\Services\Audio\StyleTagger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tags a track's audio or a project track's preview mix with its moods and
 * the genre it sounds like. A released track takes its project track's tags
 * when it has them, unless it brought its own audio. With `lyrics` set only
 * the lyrics are read again.
 */
class TagStyle implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public int $timeout = 1000;

    public int $uniqueFor = 1200;

    public function __construct(
        public Track|ProjectTrack $owner,
        public bool $inherit = false,
        public bool $lyrics = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->owner::class . ':' . $this->owner->getKey() . ($this->lyrics ? ':lyrics' : '');
    }

    public function handle(StyleTagger $tagger): void
    {
        if (! AiSettings::autoStyles()) {
            return;
        }

        $owner = $this->owner->refresh();

        if ($this->lyrics && $tagger->relisten($owner)) {
            return;
        }

        if ($this->inherit && $owner instanceof Track && $tagger->inherit($owner)) {
            return;
        }

        $tagger->detect($owner);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Genre and mood detection failed', [
            'owner' => $this->uniqueId(),
            'error' => $e?->getMessage(),
            'exception' => $e,
        ]);
    }
}
