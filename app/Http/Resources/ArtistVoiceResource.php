<?php

namespace App\Http\Resources;

use App\Models\ArtistVoice;
use App\Services\Ai\AiSettings;
use App\Services\Artists\ArtistVoices;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin ArtistVoice
 */
class ArtistVoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $voices = app(ArtistVoices::class);
        $studio = AiSettings::musicModel();
        $composer = AiSettings::composerMusicModel();

        return [
            'artistId' => $this->artist_id,
            'artistName' => $this->artist?->name,
            'description' => $this->description,
            'status' => $this->status,
            'source' => $this->source,
            'seconds' => $this->seconds,
            'sampleUrl' => $this->hasSample() ? $voices->url($this->resource) : null,
            'built' => (bool) $this->persona_id,
            'check' => $this->check_status,
            'phrase' => $this->check_status === ArtistVoice::CHECK_RECORDING ? $this->check_phrase : null,
            'failure' => $this->failure,
            'busy' => $this->isBusy(),
            'studio' => (bool) $studio->voiceFrom($this->resource),
            'composer' => (bool) $composer->voiceFrom($this->resource),
            'sampleCredits' => $composer->takesVoices() ? $composer->credits(ArtistVoices::SAMPLE_SECONDS) : null,
            'minSeconds' => ArtistVoices::MIN_SECONDS,
            'maxSeconds' => ArtistVoices::MAX_SECONDS,
            'updatedAt' => $this->updated_at,
        ];
    }
}
