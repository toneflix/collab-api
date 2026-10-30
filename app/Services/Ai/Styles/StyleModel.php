<?php

namespace App\Services\Ai\Styles;

use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Audio\Fingerprinter;
use Symfony\Component\Process\Process;

/**
 * Our CLAP model on Replicate (models/style-tagger). It hears a stretch from
 * the middle of a song and scores it against groups of text labels.
 */
class StyleModel
{
    /** How much audio the model hears, in seconds */
    public const SECONDS = 120;

    /** Seconds of audio in each window the model embeds */
    public const WINDOW = 10;

    public function __construct(protected Fingerprinter $fingerprinter) {}

    /**
     * Score the audio against each group of labels. Every group's scores add
     * up to 1.
     *
     * @param  array<string, array<int|string, string>>  $labels  group => [key => prompt]
     * @return array<string, array<int|string, float>>
     */
    public function score(string $path, float $duration, array $labels): array
    {
        $model = AiSettings::styleModel();

        if (! $model) {
            throw new ReplicateException('Set the genre and mood model in the AI settings.');
        }

        $client = ReplicateClient::make();
        $excerpt = $this->excerpt($path, $duration);

        try {
            $url = $client->upload($excerpt, 'audio/mpeg');
        } finally {
            @unlink($excerpt);
        }

        $prediction = $client->run($model, [
            'audio' => $url,
            'labels' => json_encode($labels, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE),
            'window' => self::WINDOW,
            'max_windows' => intdiv(self::SECONDS, self::WINDOW),
        ], 900);

        $output = (array) $prediction['output'];

        return collect($labels)
            ->map(fn(array $prompts, string $group) => collect((array) ($output[$group] ?? []))
                ->only(array_map('strval', array_keys($prompts)))
                ->map(fn($score) => (float) $score)
                ->all())
            ->all();
    }

    /**
     * Cut a mono MP3 from the middle of the audio.
     */
    protected function excerpt(string $path, float $duration): string
    {
        $start = max(0, ($duration - self::SECONDS) / 2);
        $base = tempnam(sys_get_temp_dir(), 'style-');
        $out = "{$base}.mp3";

        @unlink($base);

        (new Process([
            $this->fingerprinter->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y',
            '-ss', (string) round($start, 2), '-t', (string) self::SECONDS, '-i', $path,
            '-vn', '-ac', '1', '-ar', '48000', '-b:a', '96k', $out,
        ]))
            ->setTimeout(120)
            ->mustRun();

        return $out;
    }
}
