<?php

namespace App\Services\Ai\Music;

/**
 * ElevenLabs Music v2 on Cloudflare Workers AI: songs or instrumentals from a
 * prompt, trained on licensed music.
 */
class ElevenLabsMusicV2 extends CloudflareMusic
{
    public const ID = 'elevenlabs/music-v2';

    public const NAME = 'ElevenLabs Music v2';

    public const DESCRIPTION = 'Songs or instrumentals from a prompt, on Cloudflare Workers AI. Trained on licensed music and cleared for commercial use.';

    public const CREDITS_SETTING = 'ai_music_cf_elevenlabs_credits';

    public const PRICING = self::PER_10_SECONDS;

    public function maxSeconds(): ?int
    {
        return 600;
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
            'output_format' => 'mp3_44100_192',
            'seed' => $input['seed'] ?? null,
        ], fn($value) => $value !== null);
    }
}
