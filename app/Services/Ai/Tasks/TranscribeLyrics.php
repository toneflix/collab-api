<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\Lyric;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Lyrics\Lyrics;
use App\Services\Lyrics\Transcriber;
use App\Services\Studio\StemFiles;

/**
 * Writes out the lyrics sung on a stem with Whisper, timed to where the stem
 * sits on the track, as the track's lyric sheet.
 */
class TranscribeLyrics implements AiTask
{
    public function __construct(
        protected StemFiles $files,
        protected Transcriber $transcriber,
        protected Lyrics $lyrics,
    ) {}

    public function credits(array $input): int
    {
        return AiSettings::lyricsCredits();
    }

    public function describe(array $input): string
    {
        return 'Lyrics: ' . ($input['title'] ?? 'stem');
    }

    public function engine(array $input): AiEngine
    {
        return AiEngine::REPLICATE;
    }

    public function handle(AiJob $job): array
    {
        /** @var ProjectTrackStem $stem */
        $stem = ProjectTrackStem::query()
            ->findOrFail($job->input['stem_id']);

        $path = $this->files->local($stem) ?? throw new ReplicateException('That stem\'s audio file is missing.');

        try {
            $result = $this->transcriber->transcribe($path);
        } finally {
            @unlink($path);
        }

        $lines = $this->place($result['lines'], $stem->meta ?? []);

        if (! $lines) {
            return ['lines' => 0, 'notice' => 'No singing was found on that stem, so the lyric sheet was left as it was.'];
        }

        $this->lyrics->save($job->track, $lines, Lyric::TRANSCRIBED, $job->user, [
            'language' => $result['language'],
            'ai_job_id' => $job->id,
        ]);

        return ['lines' => count($lines)];
    }

    /**
     * Move the lines from the stem's own time to the track's, leaving out
     * words in the parts of the audio the stem trims off.
     *
     * @param  list<array{start: float, end: float, text: string}>  $lines
     * @param  iterable<string, mixed>  $meta
     * @return list<array{start: float, end: float, text: string}>
     */
    protected function place(array $lines, iterable $meta): array
    {
        $meta = collect($meta);
        $start = (float) ($meta['start'] ?? 0);
        $cueIn = (float) ($meta['cuein'] ?? 0);
        $cueOut = isset($meta['cueout']) && (float) $meta['cueout'] > 0 ? (float) $meta['cueout'] : null;

        return collect($lines)
            ->filter(fn(array $line) => $line['end'] > $cueIn && ($cueOut === null || $line['start'] < $cueOut))
            ->map(fn(array $line) => [
                'start' => round($start + max(0, $line['start'] - $cueIn), 2),
                'end' => round($start + ($cueOut === null ? $line['end'] : min($line['end'], $cueOut)) - $cueIn, 2),
                'text' => $line['text'],
            ])
            ->values()
            ->all();
    }
}
