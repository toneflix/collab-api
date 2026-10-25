<?php

namespace App\Services\Ai\Music;

/**
 * Stable Audio 2.5: instrumentals, loops and sound beds up to 190 seconds,
 * with no vocals.
 */
class StableAudio25 extends MusicModel
{
    public const ID = 'stability-ai/stable-audio-2.5';

    public const NAME = 'Stable Audio 2.5';

    public const DESCRIPTION = 'Instrumentals, loops and sound beds up to 190 seconds, with no vocals. Trained on licensed music.';

    public const CREDITS_SETTING = 'ai_music_stable_audio_credits';

    public function maxSeconds(): ?int
    {
        return 190;
    }

    public function instrumentalOnly(): bool
    {
        return true;
    }

    public function input(array $input, ?string $reference = null): array
    {
        return array_filter([
            'duration' => (int) $input['seconds'],
            'steps' => 8,
            'seed' => $input['seed'] ?? null,
        ], fn($value) => $value !== null);
    }
}
