<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\Lyric;
use App\Models\ProjectTrackStem;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Lyrics\LyricAligner;
use App\Services\Lyrics\Lyrics;
use App\Services\Lyrics\Transcriber;
use App\Services\Studio\StemFiles;

/**
 * Writes out the lyrics sung on a stem with Whisper, timed to where the stem
 * sits on the track, as the track's lyric sheet. In sync mode it keeps the
 * sheet's own words and only takes the times.
 */
class TranscribeLyrics implements AiTask
{
    public function __construct(
        protected StemFiles $files,
        protected Transcriber $transcriber,
        protected Lyrics $lyrics,
        protected LyricAligner $aligner,
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
        return AiSettings::lyricsEngine();
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

        $sheet = ($job->input['mode'] ?? null) === 'sync'
            ? $this->lyrics->for($job->track)
            : null;

        if ($sheet) {
            return $this->sync($job, $sheet, $lines);
        }

        $this->lyrics->save($job->track, $lines, Lyric::TRANSCRIBED, $job->user, [
            'language' => $result['language'],
            'ai_job_id' => $job->id,
        ]);

        return ['lines' => count($lines)];
    }

    /**
     * Time the sheet's lines from the words heard, keeping lines nothing matched as they were.
     *
     * @param  list<array{start: float, end: float, text: string}>  $heard
     * @return array{lines: int, notice?: string}
     */
    protected function sync(AiJob $job, Lyric $sheet, array $heard): array
    {
        $timed = $this->aligner->align($sheet->lines, $heard);
        $synced = 0;

        foreach ($timed as $i => $line) {
            if ($line['start'] === null) {
                $timed[$i] = [...$line, 'start' => $sheet->lines[$i]['start'] ?? null, 'end' => $sheet->lines[$i]['end'] ?? null];
            } else {
                $synced++;
            }
        }

        if (! $synced) {
            return ['lines' => 0, 'notice' => 'None of the words on that stem matched the lyric sheet, so it was left as it was.'];
        }

        $this->lyrics->save($job->track, $timed, $sheet->source, $job->user, [
            'language' => $sheet->language ?? null,
            'ai_job_id' => $job->id,
        ]);

        return ['lines' => $synced];
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
