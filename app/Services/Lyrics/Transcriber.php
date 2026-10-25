<?php

namespace App\Services\Lyrics;

use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;

/**
 * Writes out the words sung in an audio file with Whisper on Replicate, as
 * lines timed to the audio.
 */
class Transcriber
{
    /**
     * Whisper's own test for a segment that is really silence or music.
     */
    protected const NO_SPEECH = 0.6;

    protected const LOW_CONFIDENCE = -1.0;

    /**
     * @return array{lines: list<array{start: float, end: float, text: string}>, language: ?string, model: string}
     */
    public function transcribe(string $path): array
    {
        $client = ReplicateClient::make();

        $prediction = $client->run($this->model(), [
            'audio' => $client->upload($path, mime_content_type($path) ?: 'audio/mpeg'),
            'model' => 'large-v3',
            'transcription' => 'plain text',
            'translate' => false,
            'temperature' => 0,
            'condition_on_previous_text' => false,
        ]);

        $output = (array) $prediction['output'];

        if (! isset($output['segments']) && ! isset($output['transcription'])) {
            throw new ReplicateException('Whisper returned no transcription.');
        }

        return [
            'lines' => $this->lines($output),
            'language' => isset($output['detected_language']) ? (string) $output['detected_language'] : null,
            'model' => $this->model(),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array{start: float, end: float, text: string}>
     */
    protected function lines(array $output): array
    {
        return collect((array) ($output['segments'] ?? []))
            ->filter(fn ($segment) => is_array($segment) && ! $this->isSilence($segment))
            ->map(fn (array $segment) => [
                'start' => round((float) ($segment['start'] ?? 0), 2),
                'end' => round((float) ($segment['end'] ?? 0), 2),
                'text' => trim((string) ($segment['text'] ?? '')),
            ])
            ->filter(fn (array $line) => $line['text'] !== '')
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

    protected function model(): string
    {
        return (string) config('ai.providers.replicate.models.lyrics.default', 'openai/whisper');
    }
}
