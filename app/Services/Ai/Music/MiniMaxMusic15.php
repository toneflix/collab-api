<?php

namespace App\Services\Ai\Music;

/**
 * MiniMax Music 1.5: songs up to 4 minutes sung from the lyrics, which can
 * follow a reference song, voice or instrumental.
 */
class MiniMaxMusic15 extends ReplicateMusic
{
    public const ID = 'minimax/music-1.5';

    public const NAME = 'MiniMax Music 1.5';

    public const DESCRIPTION = 'Songs up to 4 minutes sung from your lyrics, and the only model here that can follow a reference song, voice or instrumental. MiniMax\'s terms give no explicit commercial license.';

    public const CREDITS_SETTING = 'ai_music_minimax_15_credits';

    public function promptLength(): array
    {
        return [10, 300];
    }

    public function lyricsLength(): ?array
    {
        return [10, 600];
    }

    public function references(): array
    {
        return ['song', 'voice', 'instrumental'];
    }

    public function input(array $input, ?string $reference = null): array
    {
        $kind = in_array($input['reference_kind'] ?? null, $this->references(), true) ? $input['reference_kind'] : 'song';

        return array_filter([
            'lyrics' => (string) $input['lyrics'],
            'sample_rate' => 44100,
            'bitrate' => 256000,
            "{$kind}_file" => $reference,
        ], fn($value) => $value !== null);
    }
}
