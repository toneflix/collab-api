<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\Audio\AudioMatcher;
use App\Services\Audio\TrackAudio;
use App\Services\Moderation\Moderation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fingerprints a track's audio and flags it for review when it sounds like
 * another creator's earlier track.
 */
class FingerprintTrack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Track $track) {}

    public function handle(AudioMatcher $matcher, Moderation $moderation, TrackAudio $audio): void
    {
        $local = $audio->local($this->track);
        if (! $local) {
            return;
        }

        try {
            $fingerprint = $matcher->store($this->track, $local, (float) $this->track->duration);
        } finally {
            @unlink($local);
        }

        $moderation->flagMatches($this->track, $matcher->matches($fingerprint));
    }
}
