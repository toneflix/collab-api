<?php

namespace App\Services\Ai\Music;

use App\Enums\AiEngine;
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
            ], fn($value) => $value !== null);
        }

        return array_filter([
            'customMode' => false,
            'instrumental' => $instrumental,
            'model' => $version,
            'prompt' => $prompt,
            'lyrics' => $lyrics !== '' ? $lyrics : null,
            'audioUrls' => $reference ? [$reference] : null,
        ], fn($value) => $value !== null);
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
