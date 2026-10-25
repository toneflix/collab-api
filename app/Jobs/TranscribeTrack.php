<?php

namespace App\Jobs;

use App\Models\Lyric;
use App\Models\Track;
use App\Services\Ai\AiSettings;
use App\Services\Audio\TrackAudio;
use App\Services\Lyrics\Lyrics;
use App\Services\Lyrics\Transcriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives a newly released track its lyrics: those of the project track it came
 * from, or else a Whisper transcription of its audio. Lyrics the creator
 * wrote themselves are kept.
 */
class TranscribeTrack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public int $timeout = 900;

    public function __construct(public Track $track) {}

    public function handle(Lyrics $lyrics, Transcriber $transcriber, TrackAudio $audio): void
    {
        if ($lyrics->inherit($this->track) || $lyrics->for($this->track)?->source === Lyric::WRITTEN) {
            return;
        }

        if (! AiSettings::autoLyrics()) {
            return;
        }

        $local = $audio->local($this->track, withPreview: true);

        if (! $local) {
            return;
        }

        try {
            $result = $transcriber->transcribe($local);
        } finally {
            @unlink($local);
        }

        $lyrics->save($this->track, $result['lines'], Lyric::TRANSCRIBED, null, ['language' => $result['language']]);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Lyrics transcription failed', [
            'track' => $this->track->id,
            'error' => $e?->getMessage(),
            'exception' => $e,
        ]);
    }
}
