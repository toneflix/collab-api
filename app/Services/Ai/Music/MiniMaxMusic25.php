<?php

namespace App\Services\Ai\Music;

/**
 * MiniMax Music 2.5: full songs with vocals sung from the lyrics, styled by
 * the prompt. The model decides the length, up to about 5 minutes.
 */
class MiniMaxMusic25 extends ReplicateMusic
{
    public const ID = 'minimax/music-2.5';

    public const NAME = 'MiniMax Music 2.5';

    public const DESCRIPTION = 'Full songs sung from your lyrics, up to about 5 minutes. Returns lossless WAV. MiniMax\'s terms give no explicit commercial license.';

    public const CREDITS_SETTING = 'ai_music_minimax_25_credits';

    public function lyricsLength(): ?array
    {
        return [1, 3500];
    }

    public function input(array $input, ?string $reference = null): array
    {
        return [
            'lyrics' => (string) $input['lyrics'],
            'audio_format' => 'wav',
            'sample_rate' => 44100,
        ];
    }
}
