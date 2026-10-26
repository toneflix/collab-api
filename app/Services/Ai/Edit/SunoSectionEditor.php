<?php

namespace App\Services\Ai\Edit;

use App\Enums\AiEngine;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Suno\SunoClient;

/**
 * Suno's replace section, through sunoapi.org, on uploaded audio. The
 * section must be at least 10 seconds and at most half of the clip.
 */
class SunoSectionEditor implements SectionEditor
{
    /** What Suno sings in a section with no lyrics */
    protected const INSTRUMENTAL = '[Instrumental]';

    public function engine(): AiEngine
    {
        return AiEngine::SUNO;
    }

    public function model(array $input): string
    {
        return 'suno/' . ($input['suno_version'] ?? AiSettings::sunoVersion());
    }

    public function minSeconds(): float
    {
        return 10;
    }

    public function maxShare(): float
    {
        return 0.5;
    }

    public function jobSettings(): array
    {
        return ['suno_version' => AiSettings::sunoVersion()];
    }

    public function edit(string $clip, float $from, float $to, array $input): string
    {
        $client = SunoClient::make();
        $lyrics = trim((string) ($input['lyrics'] ?? '')) ?: self::INSTRUMENTAL;
        $url = $client->upload($clip);

        $title = str((string) ($input['title'] ?? '') ?: 'Section edit')
            ->limit(80, '')
            ->toString();

        $songs = $client->replaceSection([
            'uploadUrl' => $url,
            'model' => $input['suno_version'] ?? AiSettings::sunoVersion(),
            'prompt' => $lyrics,
            'tags' => trim((string) $input['prompt']),
            'title' => $title,
            'infillStartS' => round($from, 2),
            'infillEndS' => round($to, 2),
            'fullLyrics' => $lyrics,
        ]);

        return $client->download($songs[0]['url']);
    }
}
