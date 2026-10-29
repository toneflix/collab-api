<?php

namespace App\Services\Ai\Music;

use App\Enums\AiEngine;
use App\Models\ArtistVoice;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Suno\SunoClient;

/**
 * Suno, through sunoapi.org: songs with vocals or instrumentals from a style
 * prompt, sung from the lyrics or with lyrics Suno writes. Each request makes
 * two versions; admins choose whether people keep both.
 */
class SunoMusic extends MusicModel
{
    public const ID = 'suno/music';

    public const NAME = 'Suno';

    public const ENGINE = AiEngine::SUNO;

    public const DESCRIPTION = 'Songs with vocals or instrumentals from a style prompt, sung from your lyrics or with lyrics Suno writes. Runs through sunoapi.org, a reseller with no stated commercial license.';

    public const CREDITS_SETTING = 'ai_music_suno_credits';

    public const REFERENCE_MIN_SECONDS = 6;

    /**
     * Model versions admins can pick, newest first.
     *
     * @var array<string, string>
     */
    public const VERSIONS = [
        'V6' => 'v6',
        'V5_5' => 'v5.5',
        'V5' => 'v5',
        'V4_5PLUS' => 'v4.5+',
        'V4_5ALL' => 'v4.5 All',
        'V4_5' => 'v4.5',
        'V4' => 'v4',
    ];

    /** Versions that follow a requested length */
    protected const TIMED = ['V6', 'V5_5'];

    /** Versions that sing in a voice built from a person's recording */
    protected const VOICED = ['V6', 'V5_5', 'V5'];

    public function name(): string
    {
        return static::NAME . ' ' . self::VERSIONS[$this->version()];
    }

    public function nameFor(array $input): string
    {
        return static::NAME . ' ' . (self::VERSIONS[$input['suno_version'] ?? ''] ?? self::VERSIONS[$this->version()]);
    }

    /**
     * The model version admins picked, like V5_5.
     */
    public function version(): string
    {
        return AiSettings::sunoVersion();
    }

    public function maxSeconds(): ?int
    {
        return in_array($this->version(), self::TIMED, true) ? 360 : null;
    }

    public function minSeconds(): int
    {
        return 10;
    }

    public function jobSettings(): array
    {
        return ['suno_version' => $this->version()];
    }

    public function promptLength(): array
    {
        return [3, $this->version() === 'V4' ? 200 : 1000];
    }

    public function lyricsLength(): ?array
    {
        return [1, $this->version() === 'V4' ? 3000 : 5000];
    }

    public function lyricsRequired(): bool
    {
        return false;
    }

    public function instrumentalOption(): bool
    {
        return true;
    }

    public function references(): array
    {
        return ['song'];
    }

    public function takesVoices(): bool
    {
        return true;
    }

    public function voiceFrom(ArtistVoice $voice): ?string
    {
        if (! $voice->persona_id) {
            return null;
        }

        return $voice->persona_kind !== ArtistVoice::VOICE_PERSONA || in_array($this->version(), self::VOICED, true)
            ? self::VOICE_PERSONA
            : null;
    }

    /**
     * The request for Suno. With lyrics or for an instrumental, the prompt is
     * the style and the length is followed. Otherwise Suno writes the lyrics
     * from the prompt, and a reference song can guide it.
     */
    public function input(array $input, ?string $reference = null): array
    {
        $prompt = trim((string) $input['prompt']);
        $lyrics = trim((string) ($input['lyrics'] ?? ''));
        $instrumental = (bool) ($input['instrumental'] ?? false);
        $version = $input['suno_version'] ?? $this->version();
        $persona = $this->persona($input, $version);

        if (! $reference && ($instrumental || $lyrics !== '')) {
            return array_filter([
                'customMode' => true,
                'instrumental' => $instrumental,
                'model' => $version,
                'style' => $prompt,
                'title' => str($prompt)->limit(80, '')->toString(),
                'prompt' => $instrumental ? null : $lyrics,
                'lyrics' => ! $instrumental && str_starts_with($version, 'V6') ? $lyrics : null,
                'duration' => in_array($version, self::TIMED, true) && ! empty($input['seconds']) ? (int) $input['seconds'] : null,
                ...$persona,
            ], fn($value) => $value !== null);
        }

        return array_filter([
            'customMode' => false,
            'instrumental' => $instrumental,
            'model' => $version,
            'prompt' => $prompt,
            'lyrics' => $lyrics !== '' ? $lyrics : null,
            'audioUrls' => $reference ? [$reference] : null,
            ...$persona,
        ], fn($value) => $value !== null);
    }

    /**
     * The fields that sing in the job's artist voice, if it has one.
     *
     * @param  array<string, mixed>  $input
     * @return array{personaId?: string, personaModel?: string}
     */
    protected function persona(array $input, string $version): array
    {
        $id = $input['persona']['id'] ?? null;

        if (! $id) {
            return [];
        }

        $voice = ($input['persona']['kind'] ?? null) === ArtistVoice::VOICE_PERSONA;

        return array_filter([
            'personaId' => (string) $id,
            'personaModel' => in_array($version, self::VOICED, true) ? ($voice ? 'voice_persona' : 'style_persona') : null,
        ]);
    }

    public function generate(array $input, ?string $reference = null): array
    {
        $client = SunoClient::make();
        $url = $reference ? $client->upload($reference, mime_content_type($reference) ?: 'audio/mpeg') : null;

        $songs = $client->generate($this->input($input, $url));
        $kept = AiSettings::sunoKeepsBoth() ? $songs : array_slice($songs, 0, 1);

        return array_map(fn(array $song) => new Song(
            $client->download($song['url']),
            'mp3',
            $song['title'],
            $song['duration'] ?: null,
            array_filter([
                'model' => 'suno/' . ($input['suno_version'] ?? $this->version()),
                'suno' => ['task' => $song['task'], 'audio' => $song['id']],
                'image' => $song['image'],
                'tags' => $song['tags'],
            ]),
        ), $kept);
    }
}
