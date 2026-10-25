<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Stems\DemucsSplitter;
use App\Services\Ai\Stems\StemSplitter;
use App\Services\Ai\Stems\SunoSplitter;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;
use RuntimeException;

/**
 * Splits a stem's audio into parts on the engine admins chose, Demucs on
 * Replicate or Suno, and adds each part to the track as a new stem.
 */
class SplitStems implements AiTask
{
    public function __construct(
        protected StemFiles $files,
        protected StemAudio $audio,
    ) {}

    public function credits(array $input): int
    {
        return AiSettings::stemsCredits($this->engine($input));
    }

    public function describe(array $input): string
    {
        return 'Split into stems: ' . ($input['title'] ?? 'stem');
    }

    public function handle(AiJob $job): array
    {
        /** @var ProjectTrackStem $stem */
        $stem = ProjectTrackStem::query()
            ->findOrFail($job->input['stem_id']);

        $path = $this->files->local($stem) ?? throw new RuntimeException('That stem\'s audio file is missing.');

        try {
            $stems = $this->separate($job->track, $job->user, $path, (string) $stem->title, [
                'start' => (float) ($stem->meta['start'] ?? 0),
                'ai' => ['split' => $stem->id],
            ], $this->engine($job->input));
        } finally {
            @unlink($path);
        }

        return ['stems' => array_map(fn(ProjectTrackStem $s) => $s->id, $stems)];
    }

    /**
     * Split a local audio file and add the parts to the track.
     *
     * @param  array<string, mixed>  $meta
     * @return list<ProjectTrackStem>
     */
    public function separate(ProjectTrack $track, User $user, string $path, string $title, array $meta = [], ?AiEngine $engine = null): array
    {
        $splitter = $this->splitter($engine ?? AiSettings::stemsEngine());
        $files = $splitter->split($path, (array) ($meta['ai'] ?? []));
        $temp = array_values($files);

        try {
            if ($splitter->format() !== 'mp3') {
                foreach ($files as $part => $file) {
                    $files[$part] = $temp[] = $this->audio->mp3($file);
                }
            }

            $ai = array_diff_key((array) ($meta['ai'] ?? []), ['suno' => true]);

            return array_values(array_map(fn(string $part, string $file) => $this->files->addStem(
                $track, $user, $file, trim("{$title} " . ucwords($part)), [
                    ...$meta,
                    'ai' => [...$ai, 'part' => $part, 'model' => $splitter->model()],
                ]
            ), array_keys($files), $files));
        } finally {
            array_map(fn(string $file) => @unlink($file), array_unique($temp));
        }
    }

    /**
     * The engine a job splits on: the one it started with, or the current one.
     *
     * @param  array<string, mixed>  $input
     */
    public function engine(array $input): AiEngine
    {
        return AiEngine::tryFrom((string) ($input['stems_engine'] ?? '')) ?? AiSettings::stemsEngine();
    }

    protected function splitter(AiEngine $engine): StemSplitter
    {
        return app($engine === AiEngine::SUNO ? SunoSplitter::class : DemucsSplitter::class);
    }
}
