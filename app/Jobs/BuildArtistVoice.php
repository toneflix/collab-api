<?php

namespace App\Jobs;

use App\Models\ArtistVoice;
use App\Services\Artists\ArtistVoices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Has Suno check an artist's recording of its phrase and build their voice.
 */
class BuildArtistVoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 660;

    public function __construct(
        public ArtistVoice $voice,
        public string $recording,
    ) {}

    public function handle(ArtistVoices $voices): void
    {
        $voice = $this->voice->fresh();

        if ($voice?->check_status === ArtistVoice::CHECK_BUILDING) {
            $voices->buildVoice($voice, $this->recording);
        }
    }
}
