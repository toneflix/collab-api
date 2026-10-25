<?php

namespace App\Services\Ai\Stems;

use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Audio\StemAudio;
use Throwable;

/**
 * Demucs on Replicate: vocals, drums, bass and other as WAV. "Other" is the
 * song minus the first three parts, so together the parts sound exactly like
 * the song.
 */
class DemucsSplitter implements StemSplitter
{
    public const PARTS = ['vocals', 'drums', 'bass', 'other'];

    /**
     * How much louder than Demucs' own "other" the rebuilt one may be before
     * it's taken as a sign the parts didn't line up with the song, in dB.
     */
    protected const REMAINDER_HEADROOM = 6.0;

    public function __construct(protected StemAudio $audio) {}

    public function split(string $path, array $ai = []): array
    {
        $client = ReplicateClient::make();

        $prediction = $client->run($this->model(), array_filter([
            'audio' => $client->upload($path, mime_content_type($path) ?: 'audio/mpeg'),
            'model' => $this->variant(),
            'output_format' => 'wav',
        ]));

        $output = (array) $prediction['output'];
        $files = [];

        try {
            foreach (self::PARTS as $part) {
                if (ReplicateClient::isFile($output[$part] ?? null)) {
                    $files[$part] = $client->download($output[$part]);
                }
            }

            if (! $files) {
                throw new ReplicateException('Stem separation returned no stems.');
            }

            return $this->rebuildOther($path, $files);
        } catch (Throwable $e) {
            array_map(fn(string $file) => @unlink($file), $files);

            throw $e;
        }
    }

    public function format(): string
    {
        return 'wav';
    }

    public function model(): string
    {
        return (string) config('ai.providers.replicate.models.stems.default', 'ryan5453/demucs');
    }

    /**
     * Swap Demucs' "other" for the song minus vocals, drums and bass, unless
     * the result is so loud that the parts can't have lined up.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    protected function rebuildOther(string $song, array $files): array
    {
        $parts = array_diff_key($files, ['other' => true]);

        if (! isset($files['other']) || count($parts) < count(self::PARTS) - 1) {
            return $files;
        }

        $remainder = $this->audio->remainder($song, array_values($parts));
        $level = $this->audio->level($remainder);
        $limit = ($this->audio->level($files['other']) ?? 0.0) + self::REMAINDER_HEADROOM;

        if ($level === null || $level > $limit) {
            @unlink($remainder);

            return $files;
        }

        @unlink($files['other']);
        $files['other'] = $remainder;

        return $files;
    }

    /**
     * The Demucs weights to separate with, like htdemucs.
     */
    protected function variant(): ?string
    {
        return config('ai.providers.replicate.models.stems.variant') ?: null;
    }
}
