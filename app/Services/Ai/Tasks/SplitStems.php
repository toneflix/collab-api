<?php

namespace App\Services\Ai\Tasks;

use App\Models\AiJob;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Studio\StemFiles;

/**
 * Splits a stem's audio into vocals, drums, bass and other with Demucs, and
 * adds each part to the track as a new stem.
 */
class SplitStems implements AiTask
{
    public const PARTS = ['vocals', 'drums', 'bass', 'other'];

    public function __construct(protected StemFiles $files) {}

    public function credits(array $input): int
    {
        return AiSettings::stemsCredits();
    }

    public function describe(array $input): string
    {
        return 'Split into stems: ' . ($input['title'] ?? 'stem');
    }

    public function handle(AiJob $job): array
    {
        /** @var ProjectTrackStem $stem */
        $stem = ProjectTrackStem::query()->findOrFail($job->input['stem_id']);

        $path = $this->files->local($stem) ?? throw new ReplicateException('That stem\'s audio file is missing.');

        try {
            $stems = $this->separate($job->track, $job->user, $path, (string) $stem->title, [
                'start' => (float) ($stem->meta['start'] ?? 0),
                'ai' => ['split' => $stem->id],
            ]);
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
    public function separate(ProjectTrack $track, User $user, string $path, string $title, array $meta = []): array
    {
        $client = ReplicateClient::make();

        $prediction = $client->run($this->model(), [
            'audio' => $client->upload($path, mime_content_type($path) ?: 'audio/mpeg'),
            'output_format' => 'mp3',
        ]);

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

            return array_values(array_map(fn(string $part, string $file) => $this->files->addStem(
                $track, $user, $file, trim("{$title} " . ucfirst($part)), [
                    ...$meta,
                    'ai' => [...($meta['ai'] ?? []), 'part' => $part, 'model' => $this->model()],
                ]
            ), array_keys($files), $files));
        } finally {
            array_map(fn(string $file) => @unlink($file), $files);
        }
    }

    protected function model(): string
    {
        return (string) config('ai.providers.replicate.models.stems.default', 'ryan5453/demucs');
    }
}
