<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Suno\SunoClient;
use App\Services\Ai\Suno\SunoException;
use App\Services\Audio\StemAudio;
use App\Services\Audio\TrackAudio;
use App\Services\Studio\StemFiles;
use RuntimeException;

/**
 * Sings lyrics over a track's preview mix, like a beat written as notes.
 * Suno adds vocals to the uploaded mix, then splits them out, and only the
 * vocals go on the track as a new stem, so the beat stays editable.
 */
class AddVocals implements AiTask
{
    /** What Suno is told to keep out of the vocals */
    protected const AVOID = 'Off-key, distorted vocals';

    public function __construct(
        protected TrackAudio $tracks,
        protected StemFiles $files,
        protected StemAudio $audio,
    ) {}

    public function credits(array $input): int
    {
        return AiSettings::vocalsCredits();
    }

    public function describe(array $input): string
    {
        return 'Vocals: ' . str((string) ($input['title'] ?? 'track'))->limit(60);
    }

    public function engine(array $input): AiEngine
    {
        return AiEngine::SUNO;
    }

    public function handle(AiJob $job): array
    {
        $input = $job->input;
        $track = $job->track ?? throw new RuntimeException('Vocals need a track to sing on.');
        $beat = $this->tracks->localPreview($track) ?? throw new RuntimeException('Mix the track before adding vocals.');
        $temp = [$beat];

        try {
            $client = SunoClient::make();
            $version = $input['suno_version'] ?? AiSettings::sunoVersion();
            $url = $client->upload($beat);

            $title = str((string) ($input['title'] ?? '') ?: 'Vocals')
                ->limit(80, '')
                ->toString();

            $songs = $client->addVocals(array_filter([
                'uploadUrl' => $url,
                'model' => $version,
                'title' => $title,
                'style' => str((string) ($input['prompt'] ?? '') ?: $title)
                    ->limit(1000, '')
                    ->toString(),
                'negativeTags' => self::AVOID,
                'lyrics' => trim((string) ($input['lyrics'] ?? '')),
                'prompt' => trim((string) ($input['lyrics'] ?? '')),
                'vocalGender' => in_array($input['vocal_gender'] ?? null, ['m', 'f'], true) ? $input['vocal_gender'] : null,
            ], fn($value) => $value !== null && $value !== ''));

            $song = $songs[0];
            $parts = $client->separate(['taskId' => $song['task'], 'audioId' => $song['id'], 'type' => 'separate_vocal']);
            $vocals = $parts['vocals'] ?? throw new SunoException('Suno returned no vocals.');
            $path = $temp[] = $client->download($vocals);

            $stem = $this->files->addStem($track, $job->user, $path, 'Vocals', [
                'name' => 'Vocals',
                'duration' => round($this->audio->probe($path)['duration'], 3),
                'ai' => [
                    'vocals' => true,
                    'job' => $job->id,
                    'model' => "suno/{$version}",
                ],
            ]);
        } finally {
            array_map(fn(string $file) => @unlink($file), $temp);
        }

        return ['stems' => [$stem->id]];
    }
}
