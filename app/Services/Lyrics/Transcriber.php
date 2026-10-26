<?php

namespace App\Services\Lyrics;

use App\Enums\AiEngine;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Cloudflare\CloudflareClient;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Audio\StemAudio;
use RuntimeException;

/**
 * Writes out the words sung in an audio file with Whisper, on the engine
 * admins picked for lyrics, as lines timed to the audio.
 */
class Transcriber
{
    /**
     * Whisper's own test for a segment that is really silence or music.
     */
    protected const NO_SPEECH = 0.6;

    protected const LOW_CONFIDENCE = -1.0;

    public function __construct(protected StemAudio $audio) {}

    /**
     * @return array{lines: list<array{start: float, end: float, text: string}>, language: ?string, model: string}
     */
    public function transcribe(string $path): array
    {
        $engine = AiSettings::lyricsEngine();

        $output = $engine === AiEngine::CLOUDFLARE
            ? $this->onCloudflare($path)
            : $this->onReplicate($path);

        if (! isset($output['segments']) && ! isset($output['transcription']) && ! isset($output['text'])) {
            throw new RuntimeException('Whisper returned no transcription.');
        }

        return [
            'lines' => $this->lines($output),
            'language' => $output['language'] ?? null,
            'model' => $this->model($engine),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function onReplicate(string $path): array
    {
        $client = ReplicateClient::make();

        $prediction = $client->run($this->model(AiEngine::REPLICATE), [
            'audio' => $client->upload($path, mime_content_type($path) ?: 'audio/mpeg'),
            'model' => 'large-v3',
            'transcription' => 'plain text',
            'translate' => false,
            'temperature' => 0,
            'condition_on_previous_text' => false,
        ]);

        $output = (array) $prediction['output'];
        $language = $output['detected_language'] ?? null;

        return [...$output, 'language' => $language !== null ? (string) $language : null];
    }

    /**
     * Workers AI takes the audio inline, so it is sent as small mono speech audio.
     *
     * @return array<string, mixed>
     */
    protected function onCloudflare(string $path): array
    {
        $speech = $this->audio->speech($path);

        try {
            $output = CloudflareClient::make()
                ->run($this->model(AiEngine::CLOUDFLARE), [
                    'audio' => base64_encode((string) file_get_contents($speech)),
                    'task' => 'transcribe',
                    'condition_on_previous_text' => false,
                ]);
        } finally {
            @unlink($speech);
        }

        $language = data_get($output, 'transcription_info.language');

        return [...$output, 'language' => $language !== null ? (string) $language : null];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{start: float, end: float, text: string}>
     */
    protected function lines(array $output): array
    {
        return collect((array) ($output['segments'] ?? []))
            ->filter(fn($segment) => is_array($segment) && ! $this->isSilence($segment))
            ->map(fn(array $segment) => [
                'start' => round((float) ($segment['start'] ?? 0), 2),
                'end' => round((float) ($segment['end'] ?? 0), 2),
                'text' => trim((string) ($segment['text'] ?? '')),
            ])
            ->filter(fn(array $line) => $line['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    protected function isSilence(array $segment): bool
    {
        return (float) ($segment['no_speech_prob'] ?? 0) > self::NO_SPEECH
            && (float) ($segment['avg_logprob'] ?? 0) < self::LOW_CONFIDENCE;
    }

    protected function model(AiEngine $engine): string
    {
        return $engine === AiEngine::CLOUDFLARE
            ? (string) config('ai.providers.cloudflare.models.lyrics.default', '@cf/openai/whisper-large-v3-turbo')
            : (string) config('ai.providers.replicate.models.lyrics.default', 'openai/whisper');
    }
}
