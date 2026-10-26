<?php

namespace App\Services\Ai\Music;

/**
 * MiniMax Music 2.6 on Cloudflare Workers AI: full songs sung from the
 * lyrics, or with lyrics it writes from the prompt, or instrumentals. The
 * model decides the length.
 */
class MiniMaxMusic26 extends CloudflareMusic
{
    public const ID = 'minimax/music-2.6';

    public const NAME = 'MiniMax Music 2.6';

    public const DESCRIPTION = 'Full songs sung from your lyrics or lyrics it writes, or instrumentals, on Cloudflare Workers AI. MiniMax\'s terms give no explicit commercial license.';

    public const CREDITS_SETTING = 'ai_music_minimax_26_credits';

    public function lyricsLength(): ?array
    {
        return [1, 3500];
    }

    public function lyricsRequired(): bool
    {
        return false;
    }

    public function instrumentalOption(): bool
    {
        return true;
    }

    public function input(array $input, ?string $reference = null): array
    {
        $instrumental = (bool) ($input['instrumental'] ?? false);
        $lyrics = trim((string) ($input['lyrics'] ?? ''));

        return array_filter([
            'lyrics' => ! $instrumental && $lyrics !== '' ? $lyrics : null,
            'lyrics_optimizer' => ! $instrumental && $lyrics === '',
            'is_instrumental' => $instrumental,
            'format' => 'wav',
        ], fn($value) => $value !== null);
    }
}
