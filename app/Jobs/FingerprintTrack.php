<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\Audio\AudioMatcher;
use App\Services\Moderation\Moderation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Toneflix\LaravelFileable\Media;

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

    public function handle(AudioMatcher $matcher, Moderation $moderation): void
    {
        $local = $this->download();
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

    /**
     * Copy the audio to a local temporary file, as it may be on a cloud disk.
     */
    protected function download(): ?string
    {
        $media = new Media;
        $media->setCollectionDisk('music_tracks');
        $path = $media->mediaInfo('music_tracks', $this->track->audio)['path'] ?? null;

        if (! $path || ! $media->disk->exists($path)) {
            return null;
        }

        $stream = $media->disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }

        $local = sys_get_temp_dir().'/fp-'.Str::random(16).'.'.pathinfo($path, PATHINFO_EXTENSION);
        file_put_contents($local, $stream);
        fclose($stream);

        return $local;
    }
}
