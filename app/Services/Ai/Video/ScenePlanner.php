<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\Track;
use App\Services\Composer\Ai\JsonAnswer;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Lyrics\Lyrics;
use RuntimeException;

/**
 * Plans a music video with the Composer writing model: a look for the whole
 * video and a shot list timed to the song's lyrics, which ShotList then cuts
 * on the beat.
 *
 * @phpstan-type Plan array{style: array<string, string>, shots: list<array{start: float, end: float, kind: string, prompt: string}>}
 */
class ScenePlanner
{
    /** Lyric lines the planner reads at most */
    protected const MAX_LINES = 150;

    /** Tokens each planned shot may take in the answer */
    protected const SHOT_TOKENS = 90;

    protected const SYSTEM = <<<'TXT'
        You direct music videos. You plan one video for a song as JSON only, with no other text.

        Answer with this shape:
        {"style": {"look": "...", "setting": "...", "palette": "...", "wardrobe": "...", "character": "..."},
         "shots": [{"start": 0, "kind": "broll", "prompt": "..."}]}

        Rules:
        - "start" is the second the shot begins. Shots run until the next one starts and the last runs to the end of the song.
        - Plan a shot every 3 to 6 seconds.
        - "kind" is "performance" when the artist sings to the camera, else "broll". Use performance only where lyric lines are sung, for about 40 percent of the sung time. Instrumental parts are always broll.
        - "prompt" describes one still frame for an image model: subject, action, setting, camera angle and light, in one or two sentences. Never put text, logos, brand names or real people's names in it.
        - Performance prompts show the singer from the chest up, facing the camera, mouth visible, in the video's setting.
        - Keep one look for the whole video: the same singer (described in "character"), wardrobe, palette and world, so the shots cut together.
        - Follow the creator's brief when there is one.
        TXT;

    public function __construct(
        protected LyricModel $model,
        protected Lyrics $lyrics,
    ) {}

    public function available(): bool
    {
        return $this->model->available();
    }

    /**
     * @return Plan
     */
    public function plan(MusicVideo $video, float $maxShotSeconds): array
    {
        $track = $video->track;
        $answer = $this->model->ask(self::SYSTEM, $this->prompt($video, $track), $this->budget($video));
        $data = JsonAnswer::decode($answer);

        $shots = (new ShotList($video->duration, $track->bpm ? (float) $track->bpm : null, $maxShotSeconds))
            ->build((array) ($data['shots'] ?? []));

        if (! $shots) {
            throw new RuntimeException('The AI planner returned no shots.');
        }

        $style = collect((array) ($data['style'] ?? []))
            ->only(['look', 'setting', 'palette', 'wardrobe', 'character'])
            ->map(fn($value) => str((string) $value)
                ->squish()
                ->limit(300)
                ->toString())
            ->filter()
            ->all();

        return ['style' => $style, 'shots' => $shots];
    }

    protected function prompt(MusicVideo $video, Track $track): string
    {
        $lines = collect($this->lyrics->for($track)?->lines ?? [])
            ->filter(fn(array $line) => isset($line['start']) && filled($line['text'] ?? null))
            ->take(self::MAX_LINES)
            ->map(fn(array $line) => sprintf('%.1f-%.1f %s', $line['start'], $line['end'] ?? $line['start'], $line['text']))
            ->implode("\n");

        $facts = collect([
            'Title' => $track->title,
            'Genre' => $track->genre?->name,
            'Moods' => implode(', ', (array) ($track->moods ?? [])),
            'Tempo' => $track->bpm ? "{$track->bpm} BPM" : null,
            'Length' => sprintf('%.1f seconds', $video->duration),
            'Frame' => $video->ratio,
            'Brief' => $video->brief,
        ])
            ->filter(fn($value) => filled($value))
            ->map(fn($value, string $label) => "{$label}: {$value}")
            ->implode("\n");

        return $facts . "\n\nTimed lyrics:\n" . ($lines ?: '(none, treat the song as instrumental)');
    }

    protected function budget(MusicVideo $video): int
    {
        $shots = (int) ceil($video->duration / 4);

        return min(12000, 800 + $shots * self::SHOT_TOKENS);
    }
}
