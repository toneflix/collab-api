<?php

namespace App\Services\Ai\Tasks;

use App\Broadcasting\Channels;
use App\Enums\AiEngine;
use App\Models\AiJob;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Edit\EditWindow;
use App\Services\Ai\Edit\SectionEditor;
use App\Services\Ai\Edit\SunoSectionEditor;
use App\Services\Audio\StemAudio;
use App\Services\Realtime\Realtime;
use App\Services\Studio\StemFiles;
use RuntimeException;

/**
 * Rewrites a part of a stem from a prompt. The engine gets the part with the
 * audio around it, and FFmpeg splices what comes back into a copy of the
 * stem, which goes on the track in the same place while the original is
 * muted.
 */
class EditSection implements AiTask
{
    /**
     * The editor for each engine that can rewrite a section.
     *
     * @var array<string, class-string<SectionEditor>>
     */
    public const EDITORS = [
        'suno' => SunoSectionEditor::class,
    ];

    public function __construct(
        protected StemFiles $files,
        protected StemAudio $audio,
    ) {}

    /**
     * The editor a job runs on: the one it started with, or the default.
     *
     * @param  array<string, mixed>  $input
     */
    public static function editor(array $input = []): SectionEditor
    {
        $engine = AiEngine::tryFrom((string) ($input['edit_engine'] ?? ''));

        return app(self::EDITORS[$engine?->value] ?? SunoSectionEditor::class);
    }

    public function credits(array $input): int
    {
        return AiSettings::editCredits();
    }

    public function describe(array $input): string
    {
        return 'Section edit: ' . str((string) ($input['prompt'] ?? ''))->limit(60);
    }

    public function engine(array $input): AiEngine
    {
        return self::editor($input)->engine();
    }

    public function handle(AiJob $job): array
    {
        $input = $job->input;
        $editor = self::editor($input);

        /** @var ProjectTrackStem $stem */
        $stem = $job->track
            ->stems()
            ->findOrFail($input['stem_id']);

        $source = $this->files->local($stem) ?? throw new RuntimeException('That stem\'s audio file is missing.');
        $temp = [$source];

        try {
            $duration = $this->audio->probe($source)['duration'];
            $window = EditWindow::plan((float) $input['start'], (float) $input['end'], $duration, $editor);

            $clip = $temp[] = $this->audio->cut($source, $window->clipStart, $window->clipEnd);
            $edited = $temp[] = $editor->edit(
                $clip,
                $window->infillStart - $window->clipStart,
                $window->infillEnd - $window->clipStart,
                $input,
            );

            $change = $this->audio->probe($edited)['duration'] - $window->clipLength();
            $section = $temp[] = $this->audio->fit(
                $edited,
                $window->infillStart - $window->clipStart,
                $window->infillEnd - $window->clipStart + $change,
                $window->infillLength(),
            );
            $spliced = $temp[] = $this->audio->splice(
                $source,
                $section,
                $window->infillStart,
                $window->start,
                $window->end,
                $duration,
                EditWindow::FADE,
            );
            $mp3 = $temp[] = $this->audio->mp3($spliced);

            $meta = collect($stem->meta)
                ->except(['src', 'print', 'ai', 'muted', 'soloed', 'duration', 'instrument', 'bounce'])
                ->all();

            $copy = $this->files->addStem($job->track, $job->user, $mp3, $this->title($stem), [
                ...$meta,
                'duration' => round($duration, 3),
                'ai' => [
                    'edit' => $stem->id,
                    'prompt' => (string) $input['prompt'],
                    'start' => $window->start,
                    'end' => $window->end,
                    'job' => $job->id,
                    'model' => $editor->model($input),
                ],
            ]);
        } finally {
            array_map(fn(string $file) => @unlink($file), $temp);
        }

        $this->mute($stem, $job->user);

        return ['stems' => [$copy->id], 'muted' => $stem->id];
    }

    /**
     * Mute the original stem so the edit is what plays, and tell everyone.
     */
    protected function mute(ProjectTrackStem $stem, User $by): void
    {
        $meta = collect($stem->meta)
            ->except('src')
            ->put('muted', true)
            ->all();

        $stem->meta = $meta;
        $stem->saveQuietly();

        Realtime::send(Channels::projectTrack($stem->track_id), 'stem.updated', [
            'track_id' => $stem->track_id,
            'stem_id' => $stem->id,
            'title' => $stem->title,
            'meta' => $meta,
        ], $by);
    }

    protected function title(ProjectTrackStem $stem): string
    {
        $title = str((string) ($stem->title ?: 'Stem'))
            ->replaceMatches('/\s*\(AI edit\)$/', '')
            ->toString();

        return "{$title} (AI edit)";
    }
}
