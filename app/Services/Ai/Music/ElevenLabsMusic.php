<?php

namespace App\Services\Ai\Music;

/**
 * ElevenLabs Music: songs or instrumentals from a prompt, up to 5 minutes,
 * trained on licensed music.
 */
class ElevenLabsMusic extends MusicModel
{
    public const ID = 'elevenlabs/music';

    public const NAME = 'ElevenLabs Music';

    public const DESCRIPTION = 'Songs or instrumentals from a prompt, up to 5 minutes. Trained on licensed music and cleared for commercial use.';

    public const CREDITS_SETTING = 'ai_music_credits';

    public const PRICING = self::PER_10_SECONDS;

    public function maxSeconds(): ?int
    {
        return 300;
    }

    public function instrumentalOption(): bool
    {
        return true;
    }

    public function input(array $input, ?string $reference = null): array
    {
        return array_filter([
            'music_length_ms' => (int) $input['seconds'] * 1000,
            'force_instrumental' => (bool) ($input['instrumental'] ?? false),
            'seed' => $input['seed'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
