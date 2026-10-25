<?php

namespace App\Services\Ai\Stems;

use App\Services\Ai\AiSettings;
use App\Services\Ai\Suno\SunoClient;
use App\Services\Audio\StemAudio;
use Throwable;

/**
 * Suno's stem separation: vocals and instrumental, or up to 12 parts, as
 * MP3. A song Suno made is split by its id; other audio is uploaded first.
 */
class SunoSplitter implements StemSplitter
{
    /** Largest file Suno splits, in bytes */
    protected const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(protected StemAudio $audio) {}

    public function split(string $path, array $ai = []): array
    {
        $client = SunoClient::make();
        $body = ['type' => AiSettings::sunoStemsType()];

        if (! empty($ai['suno']['task']) && ! empty($ai['suno']['audio'])) {
            $body += ['taskId' => $ai['suno']['task'], 'audioId' => $ai['suno']['audio']];
        } else {
            $body['audioUrl'] = $this->upload($client, $path);
        }

        $files = [];

        try {
            foreach ($client->separate($body) as $part => $url) {
                $files[$part] = $client->download($url);
            }

            return $files;
        } catch (Throwable $e) {
            array_map(fn(string $file) => @unlink($file), $files);

            throw $e;
        }
    }

    public function format(): string
    {
        return 'mp3';
    }

    public function model(): string
    {
        return 'suno/' . AiSettings::sunoStemsType();
    }

    /**
     * Upload the audio as an MP3, which keeps a long song under Suno's limit.
     */
    protected function upload(SunoClient $client, string $path): string
    {
        $isMp3 = mime_content_type($path) === 'audio/mpeg' && filesize($path) <= self::MAX_BYTES;
        $mp3 = $isMp3 ? $path : $this->audio->mp3($path);

        try {
            return $client->upload($mp3);
        } finally {
            if ($mp3 !== $path) {
                @unlink($mp3);
            }
        }
    }
}
