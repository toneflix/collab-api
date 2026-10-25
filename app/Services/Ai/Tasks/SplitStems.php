<?php

namespace App\Services\Ai\Tasks;

use App\Models\AiJob;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;

/**
 * Splits a stem's audio into vocals, drums, bass and other with Demucs, and
 * adds each part to the track as a new stem. "Other" is the song minus the
 * first three parts, so together the stems sound exactly like the song.
 */
class SplitStems implements AiTask
{
    public const PARTS = ['vocals', 'drums', 'bass', 'other'];

    /**
     * How much louder than Demucs' own "other" the rebuilt one may be before
     * it's taken as a sign the parts didn't line up with the song, in dB.
     */
    protected const REMAINDER_HEADROOM = 6.0;

    public function __construct(
        protected StemFiles $files,
        protected StemAudio $audio,
    ) {}

    public function credits(array $input): int
    {
        return AiSettings::stemsCredits();
    }

    public function describe(array $input): string
    {
        return 'Split into stems: '.($input['title'] ?? 'stem');
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

        return ['stems' => array_map(fn (ProjectTrackStem $s) => $s->id, $stems)];
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

        $prediction = $client->run($this->model(), array_filter([
            'audio' => $client->upload($path, mime_content_type($path) ?: 'audio/mpeg'),
            'model' => $this->variant(),
            'output_format' => 'wav',
        ]));

        $output = (array) $prediction['output'];
        $files = [];
        $temp = [];

        try {
            foreach (self::PARTS as $part) {
                if (ReplicateClient::isFile($output[$part] ?? null)) {
                    $files[$part] = $temp[] = $client->download($output[$part]);
                }
            }

            if (! $files) {
                throw new ReplicateException('Stem separation returned no stems.');
            }

            $files = $this->rebuildOther($path, $files);
            $temp = [...$temp, ...array_values($files)];

            foreach ($files as $part => $file) {
                $files[$part] = $temp[] = $this->audio->mp3($file);
            }

            return array_values(array_map(fn (string $part, string $file) => $this->files->addStem(
                $track, $user, $file, trim("{$title} ".ucfirst($part)), [
                    ...$meta,
                    'ai' => [...($meta['ai'] ?? []), 'part' => $part, 'model' => $this->model()],
                ]
            ), array_keys($files), $files));
        } finally {
            array_map(fn (string $file) => @unlink($file), array_unique($temp));
        }
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

        $files['other'] = $remainder;

        return $files;
    }

    protected function model(): string
    {
        return (string) config('ai.providers.replicate.models.stems.default', 'ryan5453/demucs');
    }

    /**
     * The Demucs weights to separate with, like htdemucs_ft.
     */
    protected function variant(): ?string
    {
        return config('ai.providers.replicate.models.stems.variant') ?: null;
    }
}
